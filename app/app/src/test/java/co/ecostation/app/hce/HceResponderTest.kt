package co.ecostation.app.hce

import org.junit.Assert.*
import org.junit.Test

/**
 * The HCE service boundary on the host: [HceResponder] is exactly what
 * [EcoHostApduService.processCommandApdu] runs, minus the Service shell.
 * These tests walk the whole path a reader drives:
 * APDU → session → credential id → nonce → key lookup → HMAC → response,
 * plus deactivation and safe pairing-time ENROLL.
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
        authenticated: (() -> Unit)? = null,
        adopted: (() -> Unit)? = null,
    ) = HceResponder(
        keys,
        window,
        credentialId = { cred },
        clockMs = { now },
        onAuthenticated = authenticated,
        onAdopted = adopted,
    )

    @Test
    fun a_full_tap_answers_with_this_credentials_own_key() {
        val keys = InMemoryCredentialKeyStore().apply { put(ApduProtocolTest.VECTOR_CRED, vectorKey) }
        val r = responder(keys)

        assertArrayEquals(hex("4843452D4F4B9000"), r.respond(select))
        assertTrue(r.selected)
        val resp = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertArrayEquals(hex(ApduProtocolTest.VECTOR_RESPONSE), resp)
        assertEquals("SELECCIONAR AID -> 9000", r.describe(select, hex("9000")))
        assertEquals("RETO -> 9000", r.describe(challenge(ApduProtocolTest.VECTOR_NONCE), resp))
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
        assertEquals("RETO -> sin llave", r.describe(challenge(ApduProtocolTest.VECTOR_NONCE), resp))
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
    fun a_successful_challenge_notifies_so_the_ui_can_clear_llave_enviada() {
        var authenticated = 0
        val keys = InMemoryCredentialKeyStore().apply { put(ApduProtocolTest.VECTOR_CRED, vectorKey) }
        val r = responder(keys, authenticated = { authenticated++ })
        r.respond(select)
        r.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertEquals(1, authenticated)
        r.respond(challenge("fedcba9876543210"))
        assertEquals(2, authenticated)

        // A failed CHALLENGE must not claim the link is verified.
        val empty = responder(InMemoryCredentialKeyStore(), authenticated = { authenticated++ })
        empty.respond(select)
        empty.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertEquals(2, authenticated)
    }

    @Test
    fun enroll_adopts_the_new_key_and_the_old_key_kept_working_until_then() {
        val keys = InMemoryCredentialKeyStore()
        val window = EnrollmentWindow(ttlMs = 60_000)
        val cred = ApduProtocolTest.VECTOR_CRED
        val oldKey = ByteArray(32) { 0x11 }
        val newKey = ByteArray(32) { 0x22 }
        keys.put(cred, oldKey)

        // What CredentialRuntime.beginEnrollment() does: only the window, no import.
        window.open(cred, newKey, now)

        val r = responder(keys, window)
        r.respond(select)
        // Until ENROLL, the OLD key still answers: a cancelled link is harmless.
        val before = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)).sliceArray(17 until 49)
        val oldMac = InMemoryCredentialKeyStore().apply { put(cred, oldKey) }
            .mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE)))
        assertArrayEquals(oldMac, before)

        val enrolled = r.respond(enroll)
        assertEquals("ENROLAR -> 9000", r.describe(enroll, enrolled))
        assertArrayEquals(newKey, enrolled.sliceArray(0 until 32))

        // The store adopted the new key; the next CHALLENGE uses it.
        val after = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)).sliceArray(17 until 49)
        val newMac = InMemoryCredentialKeyStore().apply { put(cred, newKey) }
            .mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE)))
        assertArrayEquals(newMac, after)
        assertFalse(before.contentEquals(after))

        // One release only.
        assertArrayEquals(hex("6985"), r.respond(enroll))
        assertEquals("ENROLAR -> error", r.describe(enroll, hex("6985")))
    }

    @Test
    fun only_a_successful_enroll_reports_the_adoption() {
        var adopted = 0
        var authenticated = 0
        val cred = ApduProtocolTest.VECTOR_CRED
        val window = EnrollmentWindow().apply { open(cred, ByteArray(32) { 0x21 }, now) }
        val keys = InMemoryCredentialKeyStore().apply { put(cred, ByteArray(32) { 0x20 }) }
        val r = responder(
            keys,
            window,
            authenticated = { authenticated++ },
            adopted = { adopted++ },
        )
        r.respond(select)

        // A CHALLENGE with the OLD key is not an adoption.
        r.respond(challenge(ApduProtocolTest.VECTOR_NONCE))
        assertEquals(0, adopted)
        assertEquals(1, authenticated)

        r.respond(enroll)
        assertEquals(1, adopted)
        assertEquals(1, authenticated)

        // The window is spent: a second ENROLL gets 6985 and reports nothing.
        r.respond(enroll)
        assertEquals(1, adopted)

        // A store that refuses the import must not look adopted either.
        val refusing = InMemoryCredentialKeyStore().apply { adoptResult = false }
        val secondWindow = EnrollmentWindow().apply { open(cred, ByteArray(32) { 0x22 }, now) }
        val refused = responder(refusing, secondWindow, adopted = { adopted++ })
        refused.respond(select)
        assertArrayEquals(hex("6985"), refused.respond(enroll))
        assertEquals(1, adopted)
    }

    @Test
    fun cancelling_the_window_leaves_the_old_key_intact() {
        val keys = InMemoryCredentialKeyStore()
        val cred = ApduProtocolTest.VECTOR_CRED
        val oldKey = ByteArray(32) { 0x33 }
        keys.put(cred, oldKey)
        val window = EnrollmentWindow().apply { open(cred, ByteArray(32) { 0x44 }, now) }
        window.close()

        val r = responder(keys, window)
        r.respond(select)
        assertArrayEquals(
            InMemoryCredentialKeyStore().apply { put(cred, oldKey) }
                .mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE))),
            r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)).sliceArray(17 until 49),
        )
    }

    @Test
    fun a_store_that_refuses_the_import_answers_6985_and_keeps_the_old_key() {
        val cred = ApduProtocolTest.VECTOR_CRED
        val oldKey = ByteArray(32) { 0x55 }
        val keys = InMemoryCredentialKeyStore().apply {
            put(cred, oldKey)
            adoptResult = false
        }
        val window = EnrollmentWindow().apply { open(cred, ByteArray(32) { 0x66 }, now) }

        val r = responder(keys, window)
        r.respond(select)
        assertArrayEquals(hex("6985"), r.respond(enroll))
        assertEquals("ENROLAR -> error", r.describe(enroll, hex("6985")))
        // The failed adoption did not touch the working key.
        assertArrayEquals(
            InMemoryCredentialKeyStore().apply { put(cred, oldKey) }
                .mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE))),
            r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)).sliceArray(17 until 49),
        )
    }

    @Test
    fun relinking_replaces_the_key_so_an_old_copy_is_useless() {
        val keys = InMemoryCredentialKeyStore()
        val cred = ApduProtocolTest.VECTOR_CRED
        val first = ByteArray(32) { 0x10 }
        keys.put(cred, first)
        val second = ByteArray(32) { 0x77 }
        assertTrue(keys.adopt(cred, second))

        val r = responder(keys)
        r.respond(select)
        val chal = r.respond(challenge(ApduProtocolTest.VECTOR_NONCE)).sliceArray(17 until 49)
        val withFirst = InMemoryCredentialKeyStore().apply { put(cred, first) }
            .mac(cred, ApduProtocol.challengeMessage(cred, hex(ApduProtocolTest.VECTOR_NONCE)))
        assertFalse(chal.contentEquals(withFirst))
    }
}
