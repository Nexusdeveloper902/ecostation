package co.ecostation.app.hce

import android.os.SystemClock
import java.security.SecureRandom

/**
 * Everything [EcoHostApduService] does with a command APDU, minus the
 * Android `Service` shell, so the service boundary is testable on the host.
 * The path is: APDU → per-tap session → this credential's id → nonce → key
 * lookup ([CredentialKeyStore]) → HMAC → response APDU, plus the one-time
 * ENROLL hand-off from the [EnrollmentWindow]. A successful ENROLL is adopted
 * into the store before the bytes leave ([onAdopted]); a successful CHALLENGE
 * notifies [onAuthenticated] so the UI can clear its "llave enviada" state.
 *
 * Pure Kotlin apart from the default monotonic clock; tests inject both.
 */
class HceResponder(
    private val keys: CredentialKeyStore,
    private val enrollment: EnrollmentWindow,
    private val credentialId: () -> String = { ApduProtocol.CRED_ID },
    private val clockMs: () -> Long = { SystemClock.elapsedRealtime() },
    private val onAuthenticated: (() -> Unit)? = null,
    private val onAdopting: (() -> Unit)? = null,
    private val onAdoptFailed: (() -> Unit)? = null,
    private val onAdopted: (() -> Unit)? = null,
) {
    private val session = ApduProtocol.Session()

    /** True between a matching SELECT and the next deactivation. */
    val selected: Boolean get() = session.selected

    fun respond(commandApdu: ByteArray): ByteArray {
        val id = credentialId()
        val now = clockMs()
        // A pairing window's pending key must sign CHALLENGE: ENROLL later
        // hands over that same key, and the backend checks the MAC with it.
        // Outside a window peek() is null and the stored key answers.
        val pending = enrollment.peek(id, now)
        val response = try {
            ApduProtocol.process(
                commandApdu,
                session,
                id,
                keys,
                enrollment,
                now,
                pendingKey = pending,
                onReleased = { raw ->
                    // Flag the adoption BEFORE the store import (fail-safe:
                    // worst case the UI shows LLAVE ENVIADA) and roll it back
                    // only if the store refuses the key.
                    onAdopting?.invoke()
                    val adopted = keys.adopt(id, raw)
                    if (!adopted) onAdoptFailed?.invoke()
                    adopted
                },
            )
        } finally {
            pending?.fill(0)
        }
        if (isSuccess(response)) {
            when {
                isChallenge(commandApdu) -> onAuthenticated?.invoke()
                isEnroll(commandApdu) -> onAdopted?.invoke()
            }
        }
        return response
    }

    /** RF field lost / reader deselected: the next tap starts from SELECT. */
    fun deactivate() {
        session.selected = false
    }

    /** Short, secret-free label for the UI event log. */
    fun describe(cmd: ByteArray, resp: ByteArray): String {
        val ok = isSuccess(resp)
        val name = when {
            cmd.size >= 2 && cmd[0] == 0x00.toByte() && cmd[1] == 0xA4.toByte() -> "SELECCIONAR AID"
            cmd.size >= 2 && cmd[0] == 0x80.toByte() && cmd[1] == 0x10.toByte() -> "RETO"
            cmd.size >= 2 && cmd[0] == 0x80.toByte() && cmd[1] == 0x20.toByte() -> "ENROLAR"
            else -> "comando desconocido"
        }
        val status = when {
            ok -> "9000"
            resp.contentEquals(ApduProtocol.SW_KEY_MISSING) -> "sin llave"
            else -> "error"
        }
        return "$name -> $status"
    }

    private fun isSuccess(resp: ByteArray): Boolean =
        resp.size >= 2 &&
            resp[resp.size - 2] == 0x90.toByte() &&
            resp[resp.size - 1] == 0x00.toByte()

    /** CHALLENGE is `80 10 ...`; only that command can prove the key works. */
    private fun isChallenge(cmd: ByteArray): Boolean =
        cmd.size >= 2 && cmd[0] == 0x80.toByte() && cmd[1] == 0x10.toByte()

    /** ENROLL is `80 20 ...`; a 9000 answer means the store adopted the key. */
    private fun isEnroll(cmd: ByteArray): Boolean =
        cmd.size >= 2 && cmd[0] == 0x80.toByte() && cmd[1] == 0x20.toByte()
}

/**
 * Process-wide credential wiring shared by the two OS entry points: the
 * activity (which opens enrollment windows) and the HCE service (which the
 * NFC stack may start on its own, with the app closed and the phone locked).
 */
object CredentialRuntime {
    val keys: CredentialKeyStore by lazy { AndroidKeystoreCredentialKeyStore() }
    val enrollment = EnrollmentWindow()

    /** Monotonic wall-clock that keeps counting through deep sleep. */
    fun nowMs(): Long = SystemClock.elapsedRealtime()

    /**
     * Start linking this phone. A fresh 32-byte key is held in the window for
     * ONE reader ENROLL, but it is **not** imported yet: the current key keeps
     * answering taps, so a reader that never shows up (or a cancelled link)
     * leaves a working credential. The HCE path adopts the new key only when
     * ENROLL reaches the store successfully.
     *
     * Returns false only if the window cannot be opened (never the old key's
     * fault), so the UI does not promise a link that cannot happen.
     */
    fun beginEnrollment(
        credentialId: String = ApduProtocol.CRED_ID,
        keys: CredentialKeyStore = this.keys,
        clockMs: () -> Long = { nowMs() },
    ): Boolean {
        // Warm/validate the Keystore BEFORE promising a window: the UI turns
        // this false into the "no se pudo crear su llave segura" message.
        if (!keys.isAvailable()) return false
        val raw = ByteArray(CredentialKeyStore.KEY_BYTES).also { SecureRandom().nextBytes(it) }
        try {
            // The key stays pending in the window: CHALLENGE uses it (peek)
            // and ENROLL hands it over and adopts it. Nothing is imported
            // here, so cancelling a re-link leaves the old key working.
            enrollment.open(credentialId, raw, clockMs())
        } finally {
            raw.fill(0)
        }
        return true
    }

    /**
     * Close the window and, for a first link that never reached ENROLL, remove
     * the provisional key so the phone returns to SIN VINCULAR instead of
     * showing LISTA with a key the backend never received.
     */
    fun abortEnrollment(
        credentialId: String = ApduProtocol.CRED_ID,
        deleteKey: Boolean,
        keys: CredentialKeyStore = this.keys,
    ) {
        enrollment.close()
        if (deleteKey) keys.delete(credentialId)
    }
}
