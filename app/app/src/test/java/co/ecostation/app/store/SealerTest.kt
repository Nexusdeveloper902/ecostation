package co.ecostation.app.store

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import javax.crypto.spec.SecretKeySpec

class SealerTest {

    private val k = SecretKeySpec(ByteArray(32) { it.toByte() }, "AES")
    private val otherKey = SecretKeySpec(ByteArray(32) { (it + 1).toByte() }, "AES")

    @Test
    fun round_trips_utf8() =
        assertEquals("contraseña ñ 🔑", Sealer.open(k, Sealer.seal(k, "contraseña ñ 🔑")))

    @Test
    fun same_plaintext_seals_differently() =
        assertNotEquals(Sealer.seal(k, "x"), Sealer.seal(k, "x"))

    @Test
    fun output_does_not_contain_the_plaintext() {
        val s = Sealer.seal(k, "1|abcdefTOKEN")
        assertTrue(s.startsWith("v1:"))
        assertFalse(s.contains("TOKEN"))
    }

    @Test
    fun sealed_format_is_hex_of_12_byte_iv_ciphertext_and_16_byte_tag() {
        val plaintext = "secreto"
        val s = Sealer.seal(k, plaintext)
        // v1: plus hex(iv 12 | ciphertext | tag 16), one hex digit per nibble.
        assertEquals(3 + 2 * (12 + plaintext.toByteArray(Charsets.UTF_8).size + 16), s.length)
        assertTrue(s.drop(3).all { it in "0123456789abcdef" })
    }

    @Test
    fun tampering_wrong_key_and_garbage_open_to_null() {
        val s = Sealer.seal(k, "secreto")
        val flipped = s.dropLast(1) + (if (s.last() == '0') '1' else '0')
        assertNull(Sealer.open(k, flipped))
        assertNull(Sealer.open(otherKey, s))
        assertNull(Sealer.open(k, "v1:zz"))
        assertNull(Sealer.open(k, "v0:" + s.drop(3)))
        assertNull(Sealer.open(k, ""))
        assertNull(Sealer.open(k, null))
    }

    @Test
    fun a_ciphertext_cannot_be_swapped_between_fields() {
        val token = Sealer.seal(k, "5|abc", "student_token".toByteArray())
        val password = Sealer.seal(k, "clave", "speaker_password".toByteArray())
        // Correct field opens.
        assertEquals("5|abc", Sealer.open(k, token, "student_token".toByteArray()))
        assertEquals("clave", Sealer.open(k, password, "speaker_password".toByteArray()))
        // Swapped field names fail authentication.
        assertNull(Sealer.open(k, token, "speaker_password".toByteArray()))
        assertNull(Sealer.open(k, password, "student_token".toByteArray()))
        assertNull(Sealer.open(k, token))
    }

    @Test
    fun the_default_aad_keeps_the_old_call_shape() {
        val s = Sealer.seal(k, "sin campo")
        assertEquals("sin campo", Sealer.open(k, s))
        // GCM treats no AAD and an empty AAD byte string the same.
        assertEquals("sin campo", Sealer.open(k, s, ByteArray(0)))
    }

    @Test
    fun openStrict_distinguishes_malformed_from_crypto_failure() {
        assertNull(Sealer.openStrict(k, "v1:zz"))
        assertNull(Sealer.openStrict(k, null))
        val s = Sealer.seal(k, "x")
        try {
            Sealer.openStrict(otherKey, s)
            org.junit.Assert.fail("a wrong key must throw, so the caller can tell it apart")
        } catch (e: Exception) {
            // expected
        }
    }
}
