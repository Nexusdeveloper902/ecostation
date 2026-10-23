package co.ecostation.app.store

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.security.KeyStore
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
 * If a value cannot be opened (the key was invalidated), the getter returns
 * null and drops the stale value, so the person simply signs in again.
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

    private fun readSealed(name: String): String? {
        val stored = sealed.getString(name, null) ?: return null
        val opened = try {
            Sealer.open(keystoreKey(), stored)
        } catch (e: Exception) {
            null
        }
        if (opened == null) sealed.edit().remove(name).apply()
        return opened
    }

    private fun writeSealed(name: String, value: String?) {
        if (value == null) {
            sealed.edit().remove(name).apply()
            return
        }
        val encrypted = try {
            Sealer.seal(keystoreKey(), value)
        } catch (e: Exception) {
            // An invalidated or unusable key is replaced: nothing sealed with
            // it can be read anyway, and the user signs in again.
            deleteKey()
            Sealer.seal(keystoreKey(), value)
        }
        sealed.edit().putString(name, encrypted).apply()
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
        const val KEY_SPEAKER_EMAIL = "speaker_email"
        const val KEY_SPEAKER_PASSWORD = "speaker_password"
        const val KEY_STUDENT_TOKEN = "student_token"

        /** One lock for every instance: two generators racing on one alias fail. */
        val KEY_LOCK = Any()
    }
}
