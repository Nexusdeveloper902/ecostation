package co.ecostation.app.hce

import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * TEST FIXTURE ONLY — never used by the app. A host-JVM stand-in for the
 * Android Keystore with the same contract as [AndroidKeystoreCredentialKeyStore]:
 * one key per credential id, and null (never an exception) when a key is
 * missing or unusable. Tests seed known keys with [put].
 */
class InMemoryCredentialKeyStore : CredentialKeyStore {
    private val keys = HashMap<String, ByteArray>()

    /** Set false to simulate a Keystore that refuses the imported key. */
    var adoptResult = true

    fun put(credentialId: String, key: ByteArray) {
        keys[credentialId] = key.copyOf()
    }

    /** Simulates a corrupted or invalidated Keystore entry. */
    fun corrupt(credentialId: String) {
        keys[credentialId] = ByteArray(0)
    }

    override fun hasKey(credentialId: String) = keys.containsKey(credentialId)

    override fun adopt(credentialId: String, raw: ByteArray): Boolean {
        if (!adoptResult) return false
        keys[credentialId] = raw.copyOf()
        return true
    }

    override fun mac(credentialId: String, message: ByteArray): ByteArray? = try {
        keys[credentialId]?.let { key ->
            Mac.getInstance("HmacSHA256").run {
                init(SecretKeySpec(key, "HmacSHA256"))
                doFinal(message)
            }
        }
    } catch (e: Exception) {
        null
    }

    override fun delete(credentialId: String) {
        keys.remove(credentialId)?.fill(0)
    }
}
