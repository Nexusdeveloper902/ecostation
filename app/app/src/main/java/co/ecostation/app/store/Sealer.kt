package co.ecostation.app.store

import java.security.GeneralSecurityException
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
    fun seal(key: SecretKey, plaintext: String): String {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key)
        val ciphertext = cipher.doFinal(plaintext.toByteArray(Charsets.UTF_8))
        return PREFIX + hex(cipher.iv + ciphertext)
    }

    /**
     * Opens a sealed value. Returns null for null/blank input, a wrong
     * version prefix, bad hex, a truncated payload, a wrong key or a failed
     * authentication. Never throws: a stale secret must not crash the UI.
     */
    fun open(key: SecretKey, sealed: String?): String? = try {
        if (sealed.isNullOrBlank() || !sealed.startsWith(PREFIX)) {
            null
        } else {
            val bytes = unhex(sealed.substring(PREFIX.length))
            if (bytes == null || bytes.size < IV_BYTES + TAG_BYTES) {
                null
            } else {
                val iv = bytes.copyOfRange(0, IV_BYTES)
                val body = bytes.copyOfRange(IV_BYTES, bytes.size)
                val cipher = Cipher.getInstance("AES/GCM/NoPadding")
                cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_BITS, iv))
                String(cipher.doFinal(body), Charsets.UTF_8)
            }
        }
    } catch (e: GeneralSecurityException) {
        null
    } catch (e: Exception) {
        null
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
