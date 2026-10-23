package co.ecostation.app.hce

import org.junit.Assert.*
import org.junit.Test

/**
 * The HCE service boundary on the host: [HceResponder] is exactly what
 * [EcoHostApduService.processCommandApdu] runs, minus the Service shell.
 * These tests walk the whole path a reader drives:
 * APDU → session → credential id → nonce → key lookup → HMAC → response,
 * plus deactivation and the pairing-time ENROLL.
 *
 * The expected MACs are literals computed outside this code (Python hmac),
 * so the implementation never grades itself.
 */
class HceResponderTest {

    private fun hex(s: String): ByteArray = s.chunked(2).map { it.toInt(16).toByte() }.toByteArray()

    private val select = hex("00A4040007F0010203040506")
    private fun challenge(nonceHex: String) = hex("8010000008$nonceHex")
    private val enroll = hex("8020000020")
    private val vectorKey = ByteArray(32) { it.toByte() }

    private var now = 10_000L

    private fun responder(
        keys: CredentialKeyStore,
        window: EnrollmentWindow = EnrollmentWindow(),
        cred: String = ApduProtocolTest.VECTOR_CRED,
    ) = HceResponder(keys, window, credentialId = { cred }, clockMs = { now })

    @Test
    fun a_full_tap_answers_with_this_credentials_own_key() {
        val keys = InMemoryCredentialKeyStore().apply { put(ApduProtocolTest.VECTOR_CRED, vectorKey) }
        val r = responder(keys)

        assertArrayEquals(hex("4843452D4F4B9000"), r.respond(select))
        assertTrue(r.selected)
        val resp = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertArrayEquals(hex(ApduProtocolTest.VECTOR_RESPONSE), resp)
        assertEquals("SELECT AID -> 9000", r.describe(select, hex("9000")))
        assertEquals("CHALLENGE -> 9000", r.describe(challenge(ApduProtocolTest.VECTOR_NONCE), resp))
    }

    @Test
    fun each_fresh_nonce_gets_its_own_answer() {
        val keys = InMemoryCredentialKeyStore().apply { put(ApduProtocolTest.VECTOR_CRED, vectorKey) }
        val r = responder(keys)
        r.respond(select)
        val a = r.respond(challenge("0123456789abcdef"))
        val b = r.respond(challenge("fedcba9876543210"))
        assertEquals(51, a.size)
        assertEquals(51, b.size)
        assertFalse(a.contentEquals(b))
        // Same credential id prefix, different MAC.
        assertArrayEquals(a.sliceArray(0 until 17), b.sliceArray(0 until 17))
        // And the same nonce again is deterministic (the BACKEND refuses the replay).
        assertArrayEquals(a, r.respond(challenge("0123456789abcdef")))
    }

    @Test
    fun deactivation_forces_a_new_select() {
        val keys = InMemoryCredentialKeyStore().apply { put(ApduProtocolTest.VECTOR_CRED, vectorKey) }
        val r = responder(keys)
        r.respond(select)
        r.deactivate()
        assertFalse(r.selected)
        assertArrayEquals(hex("6985"), r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)))
    }

    @Test
    fun an_unlinked_phone_answers_6A88_and_says_so() {
        val r = responder(InMemoryCredentialKeyStore())
        r.respond(select)
        val resp = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertArrayEquals(hex("6A88"), resp)
        assertEquals("CHALLENGE -> no key", r.describe(challenge(ApduProtocolTest.VECTOR_NONCE), resp))
    }

    @Test
    fun the_credential_id_is_read_per_tap_not_frozen_at_construction() {
        // CredentialId.install() may run after the service object exists.
        var cred = "ECO-AAAAAAAAAAAA"
        val keys = InMemoryCredentialKeyStore().apply { put(ApduProtocolTest.VECTOR_CRED, vectorKey) }
        val r = HceResponder(keys, EnrollmentWindow(), credentialId = { cred }, clockMs = { now })
        r.respond(select)
        assertArrayEquals(hex("6A88"), r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)))
        cred = ApduProtocolTest.VECTOR_CRED
        assertArrayEquals(hex(ApduProtocolTest.VECTOR_RESPONSE), r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)))
    }

    @Test
    fun linking_provisions_then_hands_the_same_key_to_one_enroll() {
        val keys = InMemoryCredentialKeyStore()
        val window = EnrollmentWindow(ttlMs = 60_000)
        val cred = ApduProtocolTest.VECTOR_CRED

        // What CredentialRuntime.beginEnrollment() does, with the fixture store.
        val raw = keys.provision(cred)
        window.open(cred, raw, now)
        val provisioned = raw.copyOf()
        raw.fill(0)

        val r = responder(keys, window)
        r.respond(select)
        val chal = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertEquals(51, chal.size)
        val enrolled = r.respond(enroll)
        assertEquals("ENROLL -> 9000", r.describe(enroll, enrolled))

        // The handed-over key is the stored key: the MAC the reader relayed
        // at the same tap verifies under it (the backend's pairing check).
        val key = enrolled.sliceArray(0 until 32)
        assertArrayEquals(provisioned, key)
        val probe = InMemoryCredentialKeyStore().apply { put(cred, key) }
        assertArrayEquals(
            probe.mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE))),
            chal.sliceArray(17 until 49)
        )

        // One release only.
        assertArrayEquals(hex("6985"), r.respond(enroll))
        assertEquals("ENROLL -> error", r.describe(enroll, hex("6985")))
    }

    @Test
    fun relinking_replaces_the_key_so_an_old_copy_is_useless() {
        val keys = InMemoryCredentialKeyStore()
        val cred = ApduProtocolTest.VECTOR_CRED
        val first = keys.provision(cred)
        val second = keys.provision(cred)
        assertFalse(first.contentEquals(second))

        val r = responder(keys)
        r.respond(select)
        val chal = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)).sliceArray(17 until 49)
        val withFirst = InMemoryCredentialKeyStore().apply { put(cred, first) }
            .mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE)))
        assertFalse(chal.contentEquals(withFirst))
    }
}
