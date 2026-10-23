package co.ecostation.app.hce

import android.security.keystore.KeyProperties
import android.security.keystore.KeyProtection
import java.security.KeyStore
import java.security.SecureRandom
import javax.crypto.Mac
import javax.crypto.SecretKey
import javax.crypto.spec.SecretKeySpec

/**
 * Where this credential's HMAC key lives.
 *
 * There is no shared HCE secret any more: every credential has its own
 * 256-bit key, and the backend holds the only other copy. The HCE path only
 * ever asks for a MAC — it never sees key bytes. Raw bytes exist exactly
 * once, when [provision] creates a key, so the caller can hand them to the
 * reader during the enrollment window ([EnrollmentWindow]) and wipe them.
 */
interface CredentialKeyStore {

    /** True when a usable key entry exists for [credentialId]. */
    fun hasKey(credentialId: String): Boolean

    /**
     * Create a fresh key for [credentialId], REPLACING any previous one, and
     * return its raw bytes for the one-time hand-off. The caller owns the
     * array and must wipe it.
     */
    fun provision(credentialId: String): ByteArray

    /**
     * HMAC-SHA256 of [message] under [credentialId]'s key, or null when there
     * is no key or it is unusable (corrupt, invalidated). Never throws: the
     * HCE service turns null into `6A88`.
     */
    fun mac(credentialId: String, message: ByteArray): ByteArray?

    /** Destroy the local key (re-enrollment, reset). */
    fun delete(credentialId: String)

    companion object {
        const val KEY_BYTES = 32
    }
}

/**
 * The production store: the Android Keystore (API 23+; minSdk is 24).
 *
 * Why the key is IMPORTED rather than generated inside the Keystore: the HCE
 * wire protocol is a symmetric HMAC, so the backend must hold the same key, and a
 * Keystore-generated key can never leave the Keystore. The key is therefore
 * drawn from [SecureRandom], imported at once as a non-exportable
 * `PURPOSE_SIGN` HMAC-SHA256 entry, and its raw bytes are handed out only for
 * the single enrollment hand-off. After that, the app process can use the key
 * but cannot read it back.
 *
 * Deliberate policy choices:
 * - No user-authentication requirement, so a tap still works with the phone
 *   locked (matches `requireDeviceUnlock="false"` in apduservice.xml). A lost
 *   phone is handled by revoking the credential on the backend.
 * - No StrongBox request: it is optional hardware and too slow for the RF
 *   time budget. The default TEE-backed Keystore is used where the device has
 *   one.
 * - The key is lost on uninstall (Keystore entries die with the app). The
 *   credential id survives (see [CredentialId]), so a reinstalled phone is re-linked at
 *   the desk with the same id, and its history is kept.
 */
class AndroidKeystoreCredentialKeyStore : CredentialKeyStore {

    private val keyStore: KeyStore by lazy {
        KeyStore.getInstance(PROVIDER).apply { load(null) }
    }

    override fun hasKey(credentialId: String): Boolean = try {
        keyStore.containsAlias(alias(credentialId))
    } catch (e: Exception) {
        false
    }

    override fun provision(credentialId: String): ByteArray {
        val raw = ByteArray(CredentialKeyStore.KEY_BYTES).also { SecureRandom().nextBytes(it) }
        try {
            importKey(credentialId, raw)
        } catch (e: Exception) {
            raw.fill(0)
            throw e
        }
        return raw
    }

    /**
     * Import [raw] as the non-exportable HMAC key for [credentialId]. Only
     * [provision] calls it in the app; `internal` so the instrumented tests
     * can seed a KNOWN key and check the Keystore MAC against a literal.
     */
    internal fun importKey(credentialId: String, raw: ByteArray) {
        keyStore.setEntry(
            alias(credentialId),
            KeyStore.SecretKeyEntry(SecretKeySpec(raw, KeyProperties.KEY_ALGORITHM_HMAC_SHA256)),
            KeyProtection.Builder(KeyProperties.PURPOSE_SIGN)
                .setDigests(KeyProperties.DIGEST_SHA256)
                .build(),
        )
    }

    /** The stored entry itself (instrumented tests check it is non-exportable). */
    internal fun entryFor(credentialId: String): SecretKey? =
        keyStore.getKey(alias(credentialId), null) as? SecretKey

    override fun mac(credentialId: String, message: ByteArray): ByteArray? = try {
        entryFor(credentialId)?.let {
            Mac.getInstance(KeyProperties.KEY_ALGORITHM_HMAC_SHA256).run {
                init(it)
                doFinal(message)
            }
        }
    } catch (e: Exception) {
        // KeyPermanentlyInvalidatedException, UnrecoverableKeyException,
        // provider errors: an unusable key is a missing key.
        null
    }

    override fun delete(credentialId: String) {
        try {
            keyStore.deleteEntry(alias(credentialId))
        } catch (e: Exception) {
            // Nothing to delete, or the store is unavailable: nothing to leak either.
        }
    }

    private fun alias(credentialId: String) = ALIAS_PREFIX + credentialId

    private companion object {
        const val PROVIDER = "AndroidKeyStore"
        const val ALIAS_PREFIX = "ecostation.hce.v1:"
    }
}
