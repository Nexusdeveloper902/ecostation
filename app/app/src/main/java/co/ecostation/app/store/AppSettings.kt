package co.ecostation.app.store

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import java.security.KeyStore
import java.security.UnrecoverableKeyException
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/**
 * App settings in two stores:
 *
 * - plain (`ecostation.settings`): the server address and the speaker-mode
 *   switch, which are not secrets;
 * - sealed (`ecostation.sealed`): the speaker e-mail/password and the student
 *   token, sealed with an AES-256-GCM key that lives in the Android Keystore
 *   under [KEY_ALIAS] and is never exported.
 *
 * The key needs no user authentication and is not StrongBox-backed: the
 * speaker runs unattended at the station and must reconnect after a reboot.
 * A value that cannot be opened because the key was permanently invalidated
 * is dropped (all sealed values die with that key) and the person signs in
 * again. A transient Keystore/provider failure keeps the ciphertext: the
 * getter returns null for now, but nothing is destroyed.
 */
class AppSettings(context: Context) {

    private val plain = context.applicationContext
        .getSharedPreferences(PLAIN_PREFS, Context.MODE_PRIVATE)
    private val sealed = context.applicationContext
        .getSharedPreferences(SEALED_PREFS, Context.MODE_PRIVATE)

    // ------------------------------------------------------------ plain prefs

    /** `null` when unset, in which case discovery runs. */
    var serverAddress: String?
        get() = plain.getString(KEY_SERVER_ADDRESS, null)
        set(value) = plain.edit().apply {
            if (value == null) remove(KEY_SERVER_ADDRESS) else putString(KEY_SERVER_ADDRESS, value)
        }.apply()

    var speakerEnabled: Boolean
        get() = plain.getBoolean(KEY_SPEAKER_ENABLED, false)
        set(value) = plain.edit().putBoolean(KEY_SPEAKER_ENABLED, value).apply()

    /**
     * True from the moment a link window opens until the reader adopts the
     * new key (`ENROLL` `9000`) or the person cancels. [linkPendingUntilMs]
     * bounds it in wall-clock time, so a process death cannot leave the UI
     * promising a window that no longer exists. UI flags only, never secrets:
     * the old key keeps working while they are set.
     */
    var linkPending: Boolean
        get() = plain.getBoolean(KEY_LINK_PENDING, false)
        set(value) = putBooleanIfChanged(KEY_LINK_PENDING, value)

    /** Wall-clock deadline of the open link window; 0 when none is open. */
    var linkPendingUntilMs: Long
        get() = plain.getLong(KEY_LINK_PENDING_UNTIL, 0L)
        set(value) {
            if (linkPendingUntilMs == value) return
            plain.edit().putLong(KEY_LINK_PENDING_UNTIL, value).apply()
        }

    /**
     * True after the reader adopted the new key (`ENROLL` `9000`) and until a
     * successful `CHALLENGE` proves the reader actually uses it, or until the
     * person starts an explicit re-link. Set only by the adopt-success path.
     */
    var linkSent: Boolean
        get() = plain.getBoolean(KEY_LINK_SENT, false)
        set(value) = putBooleanIfChanged(KEY_LINK_SENT, value)

    /** Link flags change from the APDU thread; skip disk writes when unchanged. */
    private fun putBooleanIfChanged(key: String, value: Boolean) {
        if (plain.getBoolean(key, false) == value) return
        plain.edit().putBoolean(key, value).apply()
    }

    // ----------------------------------------------------------- sealed prefs

    var speakerEmail: String?
        get() = readSealed(KEY_SPEAKER_EMAIL)
        set(value) = writeSealed(KEY_SPEAKER_EMAIL, value)

    var speakerPassword: String?
        get() = readSealed(KEY_SPEAKER_PASSWORD)
        set(value) = writeSealed(KEY_SPEAKER_PASSWORD, value)

    var studentToken: String?
        get() = readSealed(KEY_STUDENT_TOKEN)
        set(value) = writeSealed(KEY_STUDENT_TOKEN, value)

    private sealed class ReadResult {
        data class Value(val text: String) : ReadResult()
        object Malformed : ReadResult()
        object Invalidated : ReadResult()
        object Unavailable : ReadResult()
    }

    /**
     * Read one sealed entry.
     *
     * - A damaged *format* is dropped: it can never open.
     * - An invalidated/unrecoverable KEY wipes every sealed value and the key
     *   once: they were all sealed under the same dead key.
     * - A field-AAD failure first retries as a legacy (no-AAD) blob. Older
     *   installs sealed without AAD; if that opens, the value is re-sealed
     *   with its field AAD and returned instead of being orphaned.
     * - If both reads fail, the entry cannot be opened by this app version:
     *   it is dropped as "unavailable" so a later read does not repeat the
     *   failed crypto path forever.
     */
    private fun readSealed(name: String): String? {
        val stored = sealed.getString(name, null) ?: return null
        return when (val result = readSealedResult(name, stored)) {
            is ReadResult.Value -> result.text
            ReadResult.Malformed -> null
            ReadResult.Invalidated -> {
                sealed.edit().clear().apply()
                deleteKey()
                null
            }
            ReadResult.Unavailable -> null
        }
    }

    private fun readSealedResult(name: String, stored: String): ReadResult = try {
        val opened = Sealer.openStrict(keystoreKey(), stored, aad(name))
        if (opened == null) {
            // Wrong prefix, bad hex or truncated payload: unrecoverable format.
            sealed.edit().remove(name).apply()
            ReadResult.Malformed
        } else {
            ReadResult.Value(opened)
        }
    } catch (e: Exception) {
        if (isKeyInvalidated(e)) ReadResult.Invalidated else migrateLegacyAad(name, stored)
    }

    /**
     * Before AAD binding, values were sealed with no associated data. Try that
     * shape once; on success re-seal immediately so the next read takes the
     * bound path. A swapped field AAD still fails both reads and is dropped.
     */
    private fun migrateLegacyAad(name: String, stored: String): ReadResult = try {
        val legacy = Sealer.openStrict(keystoreKey(), stored, null)
        if (legacy == null) {
            sealed.edit().remove(name).apply()
            ReadResult.Malformed
        } else {
            sealed.edit().putString(name, Sealer.seal(keystoreKey(), legacy, aad(name))).apply()
            ReadResult.Value(legacy)
        }
    } catch (e: Exception) {
        if (isKeyInvalidated(e)) {
            ReadResult.Invalidated
        } else {
            sealed.edit().remove(name).apply()
            ReadResult.Unavailable
        }
    }

    private fun writeSealed(name: String, value: String?) {
        if (value == null) {
            sealed.edit().remove(name).apply()
            return
        }
        val encrypted = try {
            Sealer.seal(keystoreKey(), value, aad(name))
        } catch (e: Exception) {
            if (!isKeyInvalidated(e)) throw e
            // The key itself is gone; every sealed value is dead with it.
            sealed.edit().clear().apply()
            deleteKey()
            Sealer.seal(keystoreKey(), value, aad(name))
        }
        sealed.edit().putString(name, encrypted).apply()
    }

    private fun aad(name: String): ByteArray = name.toByteArray(Charsets.UTF_8)

    /** Only a permanently invalidated / unrecoverable key may be rotated. */
    private fun isKeyInvalidated(error: Throwable?): Boolean {
        var current = error
        var depth = 0
        while (current != null && depth < 8) {
            if (current is KeyPermanentlyInvalidatedException ||
                current is UnrecoverableKeyException
            ) {
                return true
            }
            current = current.cause
            depth++
        }
        return false
    }

    // --------------------------------------------------------------- keystore

    private fun keystore(): KeyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }

    private fun keystoreKey(): SecretKey = synchronized(KEY_LOCK) {
        val store = keystore()
        (store.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE)
        val spec = KeyGenParameterSpec.Builder(
            KEY_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            // No setUserAuthenticationRequired and no StrongBox: this key
            // must work while the station runs unattended.
            .build()
        generator.init(spec)
        generator.generateKey()
    }

    private fun deleteKey() {
        try {
            keystore().deleteEntry(KEY_ALIAS)
        } catch (_: Exception) {
            // Nothing to delete, or the Keystore is unavailable; the retry
            // will surface the real failure to the caller.
        }
    }

    private companion object {
        const val PLAIN_PREFS = "ecostation.settings"
        const val SEALED_PREFS = "ecostation.sealed"
        const val KEYSTORE = "AndroidKeyStore"
        const val KEY_ALIAS = "ecostation.prefs.v1"

        const val KEY_SERVER_ADDRESS = "server_address"
        const val KEY_SPEAKER_ENABLED = "speaker_enabled"
        const val KEY_LINK_PENDING = "link_pending"
        const val KEY_LINK_PENDING_UNTIL = "link_pending_until"
        const val KEY_LINK_SENT = "link_sent"
        const val KEY_SPEAKER_EMAIL = "speaker_email"
        const val KEY_SPEAKER_PASSWORD = "speaker_password"
        const val KEY_STUDENT_TOKEN = "student_token"

        /** One lock for every instance: two generators racing on one alias fail. */
        val KEY_LOCK = Any()
    }
}
