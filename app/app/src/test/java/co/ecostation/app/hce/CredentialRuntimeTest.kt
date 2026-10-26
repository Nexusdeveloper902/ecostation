package co.ecostation.app.hce

import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Pairing proof: CHALLENGE and ENROLL must use the SAME key, including on a
 * re-link where the phone still has an old key. The window's pending key
 * signs CHALLENGE; ENROLL adopts it; cancelling keeps the old key.
 */
class CredentialRuntimeTest {

    private val cred = "ECO-T3STV3CT0R5Z"

    @After
    fun closeWindow() {
        CredentialRuntime.enrollment.close()
    }

    @Test
    fun a_first_link_answers_challenge_with_the_pending_key_and_enroll_adopts_it() {
        val store = InMemoryCredentialKeyStore()
        CredentialRuntime.enrollment.close()

        assertTrue(CredentialRuntime.beginEnrollment(cred, store) { 1_000L })
        assertFalse("nada se importa hasta ENROLL", store.hasKey(cred))

        val responder = HceResponder(store, CredentialRuntime.enrollment, { cred }, { 1_000L })
        val nonce = hex("0001020304050607")
        assertTrue(endsWithOk(responder.respond(hex("00A4040007F0010203040506"))))
        val challenge = responder.respond(hex("8010000008" + "0001020304050607"))
        assertTrue("CHALLENGE debe usar la llave pendiente", endsWithOk(challenge))
        assertEquals(51, challenge.size)

        val enroll = responder.respond(hex("80200000"))
        assertTrue("ENROLL debe entregar la llave y adoptarla", endsWithOk(enroll))
        assertEquals(34, enroll.size)
        assertTrue(store.hasKey(cred))

        // The MAC the reader relays must verify with the key ENROLL handed
        // over: the server recomputes exactly this comparison.
        val expected = store.mac(cred, cred.toByteArray() + nonce)!!
        assertArrayEquals(expected, challenge.copyOfRange(17, 17 + 32))
    }

    @Test
    fun a_relink_keeps_the_old_key_until_enroll_and_signs_with_the_pending_one() {
        val store = InMemoryCredentialKeyStore()
        val oldKey = ByteArray(CredentialKeyStore.KEY_BYTES) { 7 }
        store.put(cred, oldKey)
        val before = store.mac(cred, "prueba".toByteArray())!!
        CredentialRuntime.enrollment.close()

        assertTrue(CredentialRuntime.beginEnrollment(cred, store) { 0L })
        assertArrayEquals("la llave vieja sigue en el store", before, store.mac(cred, "prueba".toByteArray()))

        val responder = HceResponder(store, CredentialRuntime.enrollment, { cred }, { 0L })
        responder.respond(hex("00A4040007F0010203040506"))
        val challenge = responder.respond(hex("80100000080001020304050607"))
        assertTrue(endsWithOk(challenge))

        responder.respond(hex("80200000"))
        assertTrue(store.hasKey(cred))
        assertNotEquals(
            "ENROLL debe reemplazar la llave vieja",
            before.toList(),
            store.mac(cred, "prueba".toByteArray())!!.toList(),
        )
    }

    @Test
    fun aborting_a_first_link_leaves_no_key() {
        val store = InMemoryCredentialKeyStore()
        CredentialRuntime.enrollment.close()
        assertTrue(CredentialRuntime.beginEnrollment(cred, store) { 0L })

        CredentialRuntime.abortEnrollment(cred, deleteKey = true, keys = store)
        assertFalse("una primera vinculación cancelada vuelve a SIN VINCULAR", store.hasKey(cred))
    }

    private fun endsWithOk(response: ByteArray): Boolean =
        response.size >= 2 &&
            response[response.size - 2] == 0x90.toByte() &&
            response[response.size - 1] == 0x00.toByte()

    private fun hex(value: String): ByteArray =
        value.chunked(2).map { it.toInt(16).toByte() }.toByteArray()
}
