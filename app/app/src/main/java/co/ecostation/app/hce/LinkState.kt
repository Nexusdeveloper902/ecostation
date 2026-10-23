package co.ecostation.app.hce

/**
 * The two persisted flags of the one-time link hand-off, resolved into one
 * phase so the card screen does not have to remember their precedence.
 *
 * - [Phase.PENDING]: a hand-off window is open (and not past its wall-clock
 *   deadline) but no reader has adopted the key yet.
 * - [Phase.SENT]: a reader adopted the key (`ENROLL` answered `9000`); it is
 *   kept until a successful `CHALLENGE` proves the reader knows that key, or
 *   until the person starts an explicit re-link.
 * - [Phase.IDLE]: neither; the old (or no) key is simply in use.
 *
 * Pure Kotlin so the state machine is host-tested; the Activity only reads
 * [AppSettings] and paints the result.
 */
object LinkState {

    enum class Phase { IDLE, PENDING, SENT }

    /**
     * A pending window wins over [sent]: if a re-link was opened while an
     * earlier adoption was still unconfirmed, the person is looking at the new
     * window, and a `CHALLENGE` answered with the old key must not close it.
     */
    fun phase(pending: Boolean, pendingUntilMs: Long, sent: Boolean, nowMs: Long): Phase = when {
        pending && nowMs < pendingUntilMs -> Phase.PENDING
        sent -> Phase.SENT
        else -> Phase.IDLE
    }

    /**
     * "Vincular de nuevo" is offered whenever a key exists and no hand-off is
     * in flight, so a lost `ENROLL` response or a backend/key mismatch never
     * requires uninstalling the app. [Phase.SENT] still offers it: an
     * unconfirmed adoption is precisely when the person needs to retry.
     */
    fun canRelink(phase: Phase, hasKey: Boolean): Boolean =
        hasKey && phase != Phase.PENDING
}
