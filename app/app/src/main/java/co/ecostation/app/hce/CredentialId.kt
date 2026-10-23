package co.ecostation.app.hce

import android.content.Context
import android.provider.Settings
import java.security.MessageDigest
import java.util.UUID
import co.ecostation.app.BuildConfig

/**
 * The per-device credential identity.
 *
 * The prototype shipped one hardcoded id (`TEST-ANDROID-001`) baked into the
 * APK, which is fine for a bench and fatal for distribution: every phone that
 * installed it would BE the same credential, and pairing one would pair all of
 * them. Each installation now derives its own id, with two properties that
 * pull in opposite directions:
 *
 *   unique per device  — so two phones are never the same credential;
 *   stable across reinstalls — so a student who clears the app or upgrades it
 *     does not silently become a different person and need re-pairing.
 *
 * Android deliberately makes the second one hard. `SharedPreferences` is wiped
 * on uninstall, so a random UUID stored there would survive nothing. The one
 * documented value with the right lifetime is **`Settings.Secure.ANDROID_ID`**:
 * unique per (device, user, signing key), stable across uninstall/reinstall,
 * and reset only by a factory reset. So that is the seed.
 *
 * Two consequences worth knowing before distributing:
 *   - ANDROID_ID is scoped to the SIGNING KEY (API 26+). A debug build and a
 *     release build are different credentials on the same phone. Sign releases
 *     with one key, always.
 *   - A factory reset issues a new identity; that phone must be paired again.
 *     This is correct — it is a different install of the OS.
 *
 * The raw ANDROID_ID never goes on the wire. It is hashed with a domain
 * separator, so the value a reader sees cannot be turned back into a system
 * identifier or correlated with any other app's use of the same seed.
 */
object CredentialId {

    /** Marks the id as an EcoStation credential in logs and at the pairing desk. */
    const val PREFIX = "ECO-"
    const val BODY_LEN = 12

    /**
     * Crockford base32: uppercase alphanumerics minus I, L, O and U — the
     * glyph pairs a person misreads when reading an id aloud to support, and
     * the letter that makes words nobody wants printed on a school card.
     */
    private const val ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

    /**
     * Domain separation: hashing the bare seed would produce the same digest
     * as any other product hashing the same ANDROID_ID.
     */
    private const val DOMAIN = "ecostation.credential.v1|"

    /**
     * Some devices are known to return a null, blank or famously duplicated
     * ANDROID_ID (the "9774d56d682e549c" bug shipped on a range of phones).
     * Those fall back to a random id, which costs reinstall-stability on that
     * handset only rather than handing several phones one shared identity.
     */
    private const val KNOWN_BAD_ANDROID_ID = "9774d56d682e549c"

    private const val PREFS = "ecostation.credential"
    private const val KEY_CREDENTIAL = "credential_id"

    /**
     * Pure seed -> id. No Android types, so the format contract is unit-tested
     * on the host exactly like the rest of the protocol.
     *
     * 12 symbols x 5 bits = 60 bits drawn from a SHA-256 digest. `byte and
     * 0x1F` is uniform here because 256 is a whole multiple of 32, so no
     * modulo bias creeps into the alphabet.
     *
     * Total length is 16 characters — deliberately the same as the
     * `TEST-ANDROID-001` the bench proved at 20/20, which keeps the CHALLENGE
     * response at 51 bytes of INF and safely inside the single I-block the
     * RC522's 64-byte FIFO can carry.
     */
    fun derive(seed: String): String {
        val digest = MessageDigest.getInstance("SHA-256")
            .digest((DOMAIN + seed).toByteArray(Charsets.UTF_8))
        val body = StringBuilder(BODY_LEN)
        for (i in 0 until BODY_LEN) {
            body.append(ALPHABET[digest[i].toInt() and 0x1F])
        }
        return PREFIX + body
    }

    /** True when [id] has the shape [derive] produces. */
    fun isWellFormed(id: String?): Boolean =
        id != null &&
            id.length == PREFIX.length + BODY_LEN &&
            id.startsWith(PREFIX) &&
            id.drop(PREFIX.length).all { it in ALPHABET }

    /**
     * Resolve this installation's credential id, caching it in preferences.
     *
     * The cache is an optimisation and a safety net, never the source of
     * stability: it is wiped on uninstall, and the id survives only because
     * re-deriving from the same ANDROID_ID lands on the same value. A device
     * that once fell back to a random id keeps it, because a stable wrong-ish
     * id beats an id that changes under a paired student.
     */
    fun resolve(context: Context): String {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        prefs.getString(KEY_CREDENTIAL, null)?.let { if (isWellFormed(it)) return it }

        val androidId = try {
            Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        } catch (e: Exception) {
            null
        }
        val usable = !androidId.isNullOrBlank() &&
            androidId.length >= 8 &&
            !androidId.equals(KNOWN_BAD_ANDROID_ID, ignoreCase = true)

        val id = derive(if (usable) androidId!! else UUID.randomUUID().toString())
        prefs.edit().putString(KEY_CREDENTIAL, id).apply()
        return id
    }

    /**
     * Point [ApduProtocol] at this device's identity. Called from both entry
     * points the OS can start — the activity and the HCE service, which the
     * NFC stack launches on its own when a reader taps a locked phone.
     *
     * A non-blank `ECO_CRED_ID` build field still wins, so the bench can pin
     * a known id without special-casing anything at runtime.
     */
    fun install(context: Context) {
        if (BuildConfig.ECO_CRED_ID.isNotBlank()) return
        ApduProtocol.CRED_ID = resolve(context)
    }
}
