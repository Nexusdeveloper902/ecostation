package co.ecostation.app.hce

import co.ecostation.app.BuildConfig
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Byte-exact credential protocol (phone side), HCE_PROTOCOL.md v1.1.
 * Wire-identical to the Pulse reference: never change a constant here.
 *
 * Pure JVM logic (no Android imports) so it is unit-testable; the AID and
 * the optional bench credential id come from BuildConfig.
 *
 * There is no HCE secret in the APK any more. CHALLENGE is still
 * `len + credId + HMAC-SHA256(key, credId || nonce) + 9000`, byte for byte,
 * but the key is this credential's own, reached only through a
 * [CredentialKeyStore] (Android Keystore in production). The backend verifies
 * the MAC with its copy; the reader only relays it. Two additions:
 * - `ENROLL` (`80 20 00 00 [Le]`) returns `key(32) + 9000`, only inside the
 *   holder-opened [EnrollmentWindow], else `6985`;
 * - CHALLENGE with no usable key answers `6A88`, never a MAC.
 */
object ApduProtocol {

    // ponytail: hex-parsed so the AID stays a build input, not source.
    val AID_HEX: String = BuildConfig.ECO_AID_HEX
    val AID: ByteArray = AID_HEX
        .chunked(2).map { it.toInt(16).toByte() }.toByteArray()
    /**
     * This installation's credential id. Defaults to the build field, which
     * ships BLANK so every install derives its own identity — see
     * [CredentialId], which assigns this once at process start. A non-blank
     * `ECO_CRED_ID` pins a known id for the bench instead.
     *
     * A `var` only so the Android side can inject the derived value without
     * dragging a Context into this pure-JVM object; nothing mutates it after
     * startup.
     */
    @Volatile
    var CRED_ID: String = BuildConfig.ECO_CRED_ID
    const val NONCE_LEN = 8
    const val HMAC_LEN = 32

    private const val CLA_PROTO: Byte = 0x00
    private const val INS_SELECT: Byte = 0xA4.toByte()
    private const val CLA_AUTH: Byte = 0x80.toByte()
    private const val INS_CHALLENGE: Byte = 0x10
    private const val INS_ENROLL: Byte = 0x20

    val SW_OK = byteArrayOf(0x90.toByte(), 0x00)
    private val SW_APP_NOT_FOUND = byteArrayOf(0x6A.toByte(), 0x82.toByte())
    private val SW_WRONG_LENGTH = byteArrayOf(0x67.toByte(), 0x00)
    private val SW_INS_UNKNOWN = byteArrayOf(0x6D.toByte(), 0x00)
    private val SW_CLA_UNKNOWN = byteArrayOf(0x6E.toByte(), 0x00)
    private val SW_CONDITIONS = byteArrayOf(0x69.toByte(), 0x85.toByte())
    /** No usable key for this credential: not linked yet, or lost (reinstall). */
    val SW_KEY_MISSING = byteArrayOf(0x6A.toByte(), 0x88.toByte())

    /** Per-tap session: SELECT must precede CHALLENGE. Reset on deactivation. */
    class Session {
        var selected: Boolean = false
    }

    /** The exact bytes the MAC covers: ASCII credential id, then the nonce. */
    fun challengeMessage(credentialId: String, nonce: ByteArray): ByteArray =
        credentialId.toByteArray(Charsets.US_ASCII) + nonce

    /**
     * SELECT response: "HCE-OK" + 9000 on AID match, 6A82 otherwise.
     * Accepts `00 A4 04 00 Lc AID [Le]` (Le optional, ignored).
     */
    fun processSelect(cmd: ByteArray, session: Session): ByteArray {
        if (cmd.size < 5) return SW_WRONG_LENGTH
        val lc = cmd[4].toInt() and 0xFF
        if (lc != AID.size || cmd.size < 5 + lc) return SW_WRONG_LENGTH
        val aid = cmd.sliceArray(5 until 5 + lc)
        if (!aid.contentEquals(AID)) return SW_APP_NOT_FOUND
        session.selected = true
        return "HCE-OK".toByteArray(Charsets.US_ASCII) + SW_OK
    }

    /**
     * CHALLENGE response: len(1) + credId + HMAC-SHA256(K_cred, credId || nonce) + 9000.
     * Accepts `80 10 00 00 Lc nonce [Le]` with Lc == 8 (Le optional, ignored).
     * No usable key -> `6A88`.
     */
    fun processChallenge(
        cmd: ByteArray,
        session: Session,
        credentialId: String,
        keys: CredentialKeyStore,
        pendingKey: ByteArray? = null,
    ): ByteArray {
        if (!session.selected) return SW_CONDITIONS
        if (cmd.size < 5) return SW_WRONG_LENGTH
        val lc = cmd[4].toInt() and 0xFF
        if (lc != NONCE_LEN || cmd.size < 5 + lc) return SW_WRONG_LENGTH
        val nonce = cmd.sliceArray(5 until 5 + NONCE_LEN)
        val credBytes = credentialId.toByteArray(Charsets.US_ASCII)
        // During pairing the phone must answer with the NEW key (the one
        // ENROLL will hand over), not the old one still in the Keystore.
        // The pairing window owns that pending key; outside it, use the store.
        val mac = if (pendingKey != null && pendingKey.size == CredentialKeyStore.KEY_BYTES) {
            hmac(pendingKey, challengeMessage(credentialId, nonce))
        } else {
            keys.mac(credentialId, challengeMessage(credentialId, nonce))
        }
        if (mac == null || mac.size != HMAC_LEN) return SW_KEY_MISSING
        return byteArrayOf(credBytes.size.toByte()) + credBytes + mac + SW_OK
    }

    /**
     * ENROLL response: key(32) + 9000, released once from [window], else
     * `6985`. Accepts `80 20 00 00 [Le]` with no data field.
     *
     * [onReleased] is the store's last chance to adopt the key before the
     * bytes reach the reader. If it returns false the response is `6985` and
     * the data is wiped: the old key was never touched, so the phone keeps
     * working and the failed link is visible. Null keeps the bench behaviour.
     */
    fun processEnroll(
        cmd: ByteArray,
        session: Session,
        credentialId: String,
        window: EnrollmentWindow,
        nowMs: Long,
        onReleased: ((ByteArray) -> Boolean)? = null,
    ): ByteArray {
        if (!session.selected) return SW_CONDITIONS
        if (cmd.size !in 4..5) return SW_WRONG_LENGTH
        val key = window.release(credentialId, nowMs) ?: return SW_CONDITIONS
        return try {
            if (onReleased != null && !onReleased(key)) {
                SW_CONDITIONS
            } else {
                key + SW_OK
            }
        } finally {
            key.fill(0)
        }
    }

    /** HMAC-SHA256 with the pairing window's raw pending key. */
    private fun hmac(key: ByteArray, message: ByteArray): ByteArray? = try {
        Mac.getInstance("HmacSHA256").run {
            init(SecretKeySpec(key, "HmacSHA256"))
            doFinal(message)
        }
    } catch (e: Exception) {
        null
    }

    /**
     * Pure formatter for the app's "Enrutado del AID" verdict (see
     * MainActivity.renderDiagnostics). Kept here — not in the Activity — so
     * the wording is unit-pinned: the bench reads this line as the live
     * answer to "would a reader's SELECT reach our service?".
     * nulls = the OS gave no answer (adapter gone / API threw before).
     */
    fun routingReport(isDefault: Boolean?, aids: List<String>?): String {
        if (isDefault == null || aids == null) return "DESCONOCIDO — sin respuesta del adaptador NFC"
        val aidLine = if (aids.isEmpty()) "¿no hay AID registrado?" else aids.joinToString(",")
        return if (isDefault) {
            "ENRUTADO (predeterminado para $aidLine)"
        } else {
            "NO ENRUTADO para $aidLine — reinstala la app / revisa apps HCE en conflicto"
        }
    }

    /** Dispatches one command APDU. Never throws: malformed input -> status word. */
    fun process(
        cmd: ByteArray,
        session: Session,
        credentialId: String,
        keys: CredentialKeyStore,
        window: EnrollmentWindow,
        nowMs: Long,
        pendingKey: ByteArray? = null,
        onReleased: ((ByteArray) -> Boolean)? = null,
    ): ByteArray {
        if (cmd.size < 4) return SW_WRONG_LENGTH
        return when (cmd[0]) {
            CLA_PROTO -> when (cmd[1]) {
                INS_SELECT -> processSelect(cmd, session)
                else -> SW_INS_UNKNOWN
            }
            CLA_AUTH -> when (cmd[1]) {
                INS_CHALLENGE -> processChallenge(cmd, session, credentialId, keys, pendingKey)
                INS_ENROLL -> processEnroll(cmd, session, credentialId, window, nowMs, onReleased)
                else -> SW_INS_UNKNOWN
            }
            else -> SW_CLA_UNKNOWN
        }
    }
}
