package co.ecostation.app.hce

import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The pairing hand-off must work for a phone that has NEVER been linked:
 * the reader sends CHALLENGE before ENROLL, so a first link has to import a
 * provisional key immediately. A re-link must keep the old key until ENROLL.
 */
class CredentialRuntimeTest {

    private val cred = "ECO-T3STV3CT0R5Z"

    @After
    fun closeWindow() {
        CredentialRuntime.enrollment.close()
    }

    @Test
    fun a_first_link_imports_a_provisional_key_and_answers_challenge() {
        val store = InMemoryCredentialKeyStore()
        CredentialRuntime.enrollment.close()

        assertTrue(CredentialRuntime.beginEnrollment(cred, store) { 1_000L })
        assertTrue("la primera vinculación debe tener llave provisional", store.hasKey(cred))

        val responder = HceResponder(store, CredentialRuntime.enrollment, { cred }, { 1_000L })
        val select = hex("00A4040007F0010203040506")
        assertTrue(endsWithOk(responder.respond(select)))

        val response = responder.respond(hex("80100000080001020304050607"))
        assertTrue("CHALLENGE debe responder 9000 con la llave provisional", endsWithOk(response))
        // 1 length byte + 16-byte id + 32-byte MAC + SW
        assertTrue(response.size == 51)
    }

    @Test
    fun a_relink_keeps_the_old_key_until_enroll() {
        val store = InMemoryCredentialKeyStore()
        val oldKey = ByteArray(CredentialKeyStore.KEY_BYTES) { 7 }
        store.put(cred, oldKey)
        val before = store.mac(cred, "prueba".toByteArray())
        CredentialRuntime.enrollment.close()

        assertTrue(CredentialRuntime.beginEnrollment(cred, store) { 0L })
        assertArrayEquals(before, store.mac(cred, "prueba".toByteArray()))
    }

    @Test
    fun aborting_a_first_link_removes_the_provisional_key() {
        val store = InMemoryCredentialKeyStore()
        CredentialRuntime.enrollment.close()
        assertTrue(CredentialRuntime.beginEnrollment(cred, store) { 0L })
        assertTrue(store.hasKey(cred))

        CredentialRuntime.abortEnrollment(cred, deleteKey = true, keys = store)
        assertFalse("cancelar una primera vinculación debe volver a SIN VINCULAR", store.hasKey(cred))
    }

    private fun endsWithOk(response: ByteArray): Boolean =
        response.size >= 2 &&
            response[response.size - 2] == 0x90.toByte() &&
            response[response.size - 1] == 0x00.toByte()

    private fun hex(value: String): ByteArray =
        value.chunked(2).map { it.toInt(16).toByte() }.toByteArray()
}
