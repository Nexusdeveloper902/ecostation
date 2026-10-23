package co.ecostation.app.session

/**
 * One monitor for the session epoch, the token write and logout.
 *
 * The UI's `epoch` alone was a check-then-act race: a login thread could pass
 * the check, a logout could clear the token, and the stale thread would then
 * restore it. Every token write now goes through [saveIfCurrent], and the
 * logout reads+clears the token under the same lock after bumping the epoch.
 * Pure Kotlin so the race is host-tested.
 */
class SessionGate {

    private var epoch = 0

    /** A new session action starts; every older result is now stale. */
    @Synchronized
    fun begin(): Int = ++epoch

    /** Snapshot for an action that will save a token later. */
    @Synchronized
    fun current(): Int = epoch

    @Synchronized
    fun isCurrent(expected: Int): Boolean = expected == epoch

    /**
     * Writes [value] only if no newer action (or logout) started since
     * [expected]. Returns true when the write happened. The epoch check and
     * the write are one atomic step.
     */
    @Synchronized
    fun saveIfCurrent(expected: Int, value: String?, write: (String?) -> Unit): Boolean {
        if (expected != epoch) return false
        write(value)
        return true
    }

    /**
     * Ends the session atomically: bump the epoch, read the token that still
     * belongs to this session for revocation, then clear it. A stale login
     * thread that wakes afterwards gets a old [expected] and cannot restore
     * anything.
     */
    @Synchronized
    fun end(read: () -> String?, clear: () -> Unit): String? {
        epoch++
        val token = read()
        clear()
        return token
    }
}
