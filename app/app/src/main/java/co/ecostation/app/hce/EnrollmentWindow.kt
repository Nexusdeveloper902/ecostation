package co.ecostation.app.hce

/**
 * The only time this phone's key leaves the Keystore path.
 *
 * The holder opens it from the card screen ("Link this phone") at the pairing
 * desk. It then holds a copy of the freshly provisioned key for [ttlMs] and
 * releases it to ONE reader `ENROLL`, for the same credential id only. The
 * copy is wiped when it is released, when the window expires, and when a new
 * window replaces it.
 *
 * Single release on purpose. If something else reads the key first, the
 * desk's reader gets `6985`, the pairing visibly fails, and re-opening the
 * window provisions a NEW key, which makes the stolen copy useless.
 *
 * Pure JVM, with the clock passed in, so it is unit-tested on the host.
 */
class EnrollmentWindow(private val ttlMs: Long = DEFAULT_TTL_MS) {

    private var key: ByteArray? = null
    private var credentialId: String? = null
    private var expiresAtMs = 0L

    /** Hold [rawKey] (copied) for [credentialId] until released or expired. */
    @Synchronized
    fun open(credentialId: String, rawKey: ByteArray, nowMs: Long) {
        close()
        key = rawKey.copyOf()
        this.credentialId = credentialId
        expiresAtMs = nowMs + ttlMs
    }

    /**
     * The key for [credentialId], at most once, while the window is open.
     * Returns a copy the caller must wipe. Null means the window is closed,
     * has expired, or belongs to another id.
     */
    @Synchronized
    fun release(credentialId: String, nowMs: Long): ByteArray? {
        val held = key ?: return null
        if (nowMs >= expiresAtMs) {
            close()
            return null
        }
        if (credentialId != this.credentialId) return null
        val out = held.copyOf()
        close()
        return out
    }

    /** Milliseconds left, or 0 when closed (expired windows are wiped here too). */
    @Synchronized
    fun remainingMs(nowMs: Long): Long {
        if (key == null) return 0
        if (nowMs >= expiresAtMs) {
            close()
            return 0
        }
        return expiresAtMs - nowMs
    }

    @Synchronized
    fun close() {
        key?.fill(0)
        key = null
        credentialId = null
        expiresAtMs = 0
    }

    companion object {
        const val DEFAULT_TTL_MS = 60_000L
    }
}
