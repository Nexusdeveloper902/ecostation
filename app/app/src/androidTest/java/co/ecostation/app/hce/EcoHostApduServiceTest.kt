@file:Suppress("DEPRECATION") // android.test.ServiceTestCase: the platform runner, no AndroidX

package co.ecostation.app.hce

import android.content.Intent
import android.nfc.cardemulation.HostApduService
import android.test.ServiceTestCase
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec
import co.ecostation.app.BuildConfig

/**
 * INSTRUMENTED (device/emulator) test of the real HCE service boundary.
 *
 * The real [EcoHostApduService] is started through the platform
 * ServiceTestCase, so its onCreate (CredentialId.install) and its
 * [CredentialRuntime] wiring run as they do on a phone. The test then drives
 * processCommandApdu exactly as the NFC stack would:
 * SELECT → CHALLENGE → the MAC comes from the REAL Android Keystore → response
 * APDU, plus deactivation, a missing key (6A88), and the ENROLL hand-off.
 *
 * Expected MACs are computed with the software JCA provider (SecretKeySpec),
 * independently of the Keystore provider that produces the answer, and the
 * vector case is also checked against the shared literal.
 *
 * It works on a dedicated credential id so it never touches the device's real
 * credential key. Run on a bench phone or emulator:
 *   ./gradlew :app:connectedDebugAndroidTest
 * What it cannot cover: the RF layer (ISO-DEP timing, OS AID routing). Those
 * stay in the bench checklist (pair at the station, then tap).
 */
class EcoHostApduServiceTest : ServiceTestCase<EcoHostApduService>(EcoHostApduService::class.java) {

    private lateinit var savedCredId: String
    private val keys get() = CredentialRuntime.keys as AndroidKeystoreCredentialKeyStore

    private fun hex(s: String): ByteArray = s.chunked(2).map { it.toInt(16).toByte() }.toByteArray()
    private val select = hex("00A4040007F0010203040506")
    private fun challenge(nonce: ByteArray) = hex("8010000008") + nonce
    private val enroll = hex("8020000020")

    private fun softwareMac(key: ByteArray, message: ByteArray): ByteArray =
        Mac.getInstance("HmacSHA256").run {
            init(SecretKeySpec(key, "HmacSHA256"))
            doFinal(message)
        }

    override fun setUp() {
        super.setUp()
        startService(Intent(context, EcoHostApduService::class.java))
        // onCreate ran CredentialId.install(): the device's real id is set.
        assertTrue(CredentialId.isWellFormed(ApduProtocol.CRED_ID) || BuildConfig.ECO_CRED_ID.isNotBlank())
        savedCredId = ApduProtocol.CRED_ID
        ApduProtocol.CRED_ID = TEST_CRED
        keys.delete(TEST_CRED)
        CredentialRuntime.enrollment.close()
    }

    override fun tearDown() {
        keys.delete(TEST_CRED)
        CredentialRuntime.enrollment.close()
        ApduProtocol.CRED_ID = savedCredId
        super.tearDown()
    }

    fun testChallengeIsAnsweredWithTheKeystoreKeyOfThisCredential() {
        val key = ByteArray(32) { it.toByte() }
        keys.importKey(TEST_CRED, key)
        val svc = service

        assertTrue(hex("4843452D4F4B9000").contentEquals(svc.processCommandApdu(select, null)))
        val nonce = hex("0123456789abcdef")
        val resp = svc.processCommandApdu(challenge(nonce), null)

        val cred = TEST_CRED.toByteArray(Charsets.US_ASCII)
        val expected = byteArrayOf(cred.size.toByte()) + cred + softwareMac(key, cred + nonce) + hex("9000")
        assertTrue(expected.contentEquals(resp))
        assertEquals(51, resp.size)
    }

    fun testKeystoreMatchesTheSharedLiteralVectorAndIsNotExportable() {
        keys.importKey(VECTOR_CRED, ByteArray(32) { it.toByte() })
        try {
            val mac = keys.mac(VECTOR_CRED, VECTOR_CRED.toByteArray(Charsets.US_ASCII) + hex("0123456789abcdef"))
            assertTrue(hex("ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295").contentEquals(mac))
            // RFC 4231 test case 1 through the same Keystore path.
            keys.importKey(RFC_CRED, ByteArray(20) { 0x0b })
            assertTrue(
                hex("b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7")
                    .contentEquals(keys.mac(RFC_CRED, "Hi There".toByteArray(Charsets.US_ASCII)))
            )
            // The Keystore entry never gives its bytes back.
            assertNull(keys.entryFor(VECTOR_CRED)!!.encoded)
        } finally {
            keys.delete(VECTOR_CRED)
            keys.delete(RFC_CRED)
        }
    }

    fun testFreshNoncesGiveFreshAnswersAndDeactivationResetsTheSession() {
        keys.importKey(TEST_CRED, ByteArray(32) { 9 })
        val svc = service
        svc.processCommandApdu(select, null)
        val a = svc.processCommandApdu(challenge(hex("0000000000000001")), null)
        val b = svc.processCommandApdu(challenge(hex("0000000000000002")), null)
        assertFalse(a.contentEquals(b))

        svc.onDeactivated(HostApduService.DEACTIVATION_LINK_LOSS)
        assertTrue(hex("6985").contentEquals(svc.processCommandApdu(challenge(hex("0000000000000003")), null)))
    }

    fun testAPhoneWithoutAKeyAnswers6A88AndMalformedInputNeverThrows() {
        val svc = service
        svc.processCommandApdu(select, null)
        assertTrue(hex("6A88").contentEquals(svc.processCommandApdu(challenge(hex("0123456789abcdef")), null)))
        assertTrue(hex("6700").contentEquals(svc.processCommandApdu(hex("80100000"), null)))
        assertTrue(hex("6E00").contentEquals(svc.processCommandApdu(hex("FF000000"), null)))
        assertTrue(hex("6F00").contentEquals(svc.processCommandApdu(null, null)))
    }

    fun testLinkingHandsOverTheSameKeyTheChallengeWillUseExactlyOnce() {
        // The window holds a new key; the phone has no key until the reader
        // confirms ENROLL, so nothing was imported by beginEnrollment itself.
        assertTrue(CredentialRuntime.beginEnrollment(TEST_CRED))
        assertFalse(keys.hasKey(TEST_CRED))
        val svc = service

        svc.processCommandApdu(select, null)
        val nonce = hex("a1a2a3a4a5a6a7a8")
        val enrolled = svc.processCommandApdu(enroll, null)
        assertEquals(34, enrolled.size)
        val key = enrolled.copyOf(32)
        assertTrue(keys.hasKey(TEST_CRED))

        val chal = svc.processCommandApdu(challenge(nonce), null)
        val cred = TEST_CRED.toByteArray(Charsets.US_ASCII)
        assertTrue(softwareMac(key, cred + nonce).contentEquals(chal.copyOfRange(1 + cred.size, 1 + cred.size + 32)))
        assertTrue(hex("6985").contentEquals(svc.processCommandApdu(enroll, null)))
    }

    fun testCancellingTheLinkLeavesTheOldKeyInPlace() {
        val old = ByteArray(32) { 0x5A }
        keys.importKey(TEST_CRED, old)
        assertTrue(CredentialRuntime.beginEnrollment(TEST_CRED))
        CredentialRuntime.enrollment.close()
        val svc = service
        svc.processCommandApdu(select, null)
        val nonce = hex("b1b2b3b4b5b6b7b8")
        val chal = svc.processCommandApdu(challenge(nonce), null)
        val cred = TEST_CRED.toByteArray(Charsets.US_ASCII)
        assertTrue(softwareMac(old, cred + nonce).contentEquals(chal.copyOfRange(1 + cred.size, 1 + cred.size + 32)))
        assertTrue(hex("6985").contentEquals(svc.processCommandApdu(enroll, null)))
    }

    private companion object {
        const val TEST_CRED = "ECO-NSTRMNTTST01"
        const val VECTOR_CRED = "PLS-K3Y7V3CT0R5Z"
        const val RFC_CRED = "rfc4231-tc1"
    }
}
