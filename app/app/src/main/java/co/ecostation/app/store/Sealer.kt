package co.ecostation.app.store

import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Authenticated sealing for small secrets (the speaker password, the student
 * token). Pure JVM so it is host-tested; the key itself is the caller's — on
 * Android it is an AES-256 key that never leaves the Keystore.
 *
 * Format: `"v1:" + hex(iv ‖ ciphertext ‖ tag)`. The IV is 12 bytes and fresh
 * on every call, the tag is 128 bits (the GCM default). Hex, not Base64:
 * `java.util.Base64` needs API 26 and minSdk is 24.
 *
 * [aad] binds a ciphertext to its field name in the prefs file: opening the
 * `student_token` blob as `speaker_password` (or any swapped pair) fails
 * authentication instead of yielding the other secret.
 */
object Sealer {

    private const val PREFIX = "v1:"
    private const val IV_BYTES = 12
    private const val TAG_BITS = 128
    private const val TAG_BYTES = TAG_BITS / 8
    private const val HEX = "0123456789abcdef"

    /**
     * Seals [plaintext] with a fresh IV. On Android the Keystore cipher picks
     * the IV (caller-chosen IVs are refused), so we always read `cipher.iv`
     * back instead of supplying one.
     */
    fun seal(key: SecretKey, plaintext: String, aad: ByteArray? = null): String {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key)
        updateAad(cipher, aad)
        val ciphertext = cipher.doFinal(plaintext.toByteArray(Charsets.UTF_8))
        return PREFIX + hex(cipher.iv + ciphertext)
    }

    /**
     * Opens a sealed value. Returns null for null/blank input, a wrong
     * version prefix, bad hex, a truncated payload, a wrong key or a failed
     * authentication. Never throws: a stale secret must not crash the UI.
     */
    fun open(key: SecretKey, sealed: String?, aad: ByteArray? = null): String? = try {
        openStrict(key, sealed, aad)
    } catch (e: Exception) {
        null
    }

    /**
     * Like [open] but only malformed *format* returns null; a cryptographic
     * failure (invalidated key, provider outage, swapped AAD) is thrown so
     * the caller can tell "invalidated" from "unavailable" and keep the
     * ciphertext on a transient failure.
     */
    fun openStrict(key: SecretKey, sealed: String?, aad: ByteArray? = null): String? {
        if (sealed.isNullOrBlank() || !sealed.startsWith(PREFIX)) return null
        val bytes = unhex(sealed.substring(PREFIX.length)) ?: return null
        if (bytes.size < IV_BYTES + TAG_BYTES) return null
        val iv = bytes.copyOfRange(0, IV_BYTES)
        val body = bytes.copyOfRange(IV_BYTES, bytes.size)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_BITS, iv))
        updateAad(cipher, aad)
        return String(cipher.doFinal(body), Charsets.UTF_8)
    }

    private fun updateAad(cipher: Cipher, aad: ByteArray?) {
        if (aad != null && aad.isNotEmpty()) cipher.updateAAD(aad)
    }

    private fun hex(bytes: ByteArray): String {
        val out = StringBuilder(bytes.size * 2)
        for (b in bytes) {
            val v = b.toInt() and 0xFF
            out.append(HEX[v ushr 4]).append(HEX[v and 0x0F])
        }
        return out.toString()
    }

    private fun unhex(text: String): ByteArray? {
        if (text.length % 2 != 0) return null
        val out = ByteArray(text.length / 2)
        for (i in out.indices) {
            val hi = digit(text[i * 2]) ?: return null
            val lo = digit(text[i * 2 + 1]) ?: return null
            out[i] = ((hi shl 4) or lo).toByte()
        }
        return out
    }

    private fun digit(c: Char): Int? = when (c) {
        in '0'..'9' -> c - '0'
        in 'a'..'f' -> c - 'a' + 10
        else -> null
    }
}
