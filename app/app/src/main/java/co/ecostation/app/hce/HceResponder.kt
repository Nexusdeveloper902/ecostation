package co.ecostation.app.hce

/**
 * Everything [EcoHostApduService] does with a command APDU, minus the
 * Android `Service` shell, so the service boundary is testable on the host.
 * The path is: APDU → per-tap session → this credential's id → nonce → key
 * lookup ([CredentialKeyStore]) → HMAC → response APDU, plus the one-time
 * ENROLL hand-off from the [EnrollmentWindow].
 *
 * Pure JVM: the clock is injected, and monotonic time needs no Android API.
 */
class HceResponder(
    private val keys: CredentialKeyStore,
    private val enrollment: EnrollmentWindow,
    private val credentialId: () -> String = { ApduProtocol.CRED_ID },
    private val clockMs: () -> Long = { System.nanoTime() / 1_000_000 },
) {
    private val session = ApduProtocol.Session()

    /** True between a matching SELECT and the next deactivation. */
    val selected: Boolean get() = session.selected

    fun respond(commandApdu: ByteArray): ByteArray =
        ApduProtocol.process(commandApdu, session, credentialId(), keys, enrollment, clockMs())

    /** RF field lost / reader deselected: the next tap starts from SELECT. */
    fun deactivate() {
        session.selected = false
    }

    /** Short, secret-free label for the UI event log. */
    fun describe(cmd: ByteArray, resp: ByteArray): String {
        val ok = resp.size >= 2 && resp[resp.size - 2] == 0x90.toByte() && resp[resp.size - 1] == 0x00.toByte()
        val name = when {
            cmd.size >= 2 && cmd[0] == 0x00.toByte() && cmd[1] == 0xA4.toByte() -> "SELECT AID"
            cmd.size >= 2 && cmd[0] == 0x80.toByte() && cmd[1] == 0x10.toByte() -> "CHALLENGE"
            cmd.size >= 2 && cmd[0] == 0x80.toByte() && cmd[1] == 0x20.toByte() -> "ENROLL"
            else -> "Unknown cmd"
        }
        val status = when {
            ok -> "9000"
            resp.contentEquals(ApduProtocol.SW_KEY_MISSING) -> "no key"
            else -> "error"
        }
        return "$name -> $status"
    }
}

/**
 * Process-wide credential wiring shared by the two OS entry points: the
 * activity (which opens enrollment windows) and the HCE service (which the
 * NFC stack may start on its own, with the app closed and the phone locked).
 */
object CredentialRuntime {
    val keys: CredentialKeyStore by lazy { AndroidKeystoreCredentialKeyStore() }
    val enrollment = EnrollmentWindow()

    fun nowMs(): Long = System.nanoTime() / 1_000_000

    /**
     * Start linking this phone: a NEW key replaces the old one in the
     * Keystore, and a copy waits in the window for the desk reader's
     * ENROLL. Returns false if the Keystore refused, so no window opens.
     */
    fun beginEnrollment(credentialId: String = ApduProtocol.CRED_ID): Boolean {
        val raw = try {
            keys.provision(credentialId)
        } catch (e: Exception) {
            return false
        }
        try {
            enrollment.open(credentialId, raw, nowMs())
        } finally {
            raw.fill(0)
        }
        return true
    }
}
