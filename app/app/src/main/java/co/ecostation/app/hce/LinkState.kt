package co.ecostation.app.hce

import co.ecostation.app.store.LinkFlags

/**
 * The persisted link flags, resolved into one phase and advanced by pure
 * transitions so the card screen and the HCE service cannot disagree.
 *
 * - [Phase.PENDING]: the in-memory enrollment window is still open on the
 *   **monotonic** clock. The wall-clock deadline is deliberately absent here:
 *   a forward or backward clock jump must not hide an open window nor leave a
 *   "VINCULANDO 0 s" countdown.
 * - [Phase.SENT]: a reader adopted the key (`ENROLL` answered `9000`). It is
 *   kept until an explicit re-link, or until a `CHALLENGE` inside the short
 *   post-adoption window below confirms the desk flow.
 * - [Phase.IDLE]: neither; the old (or no) key is simply in use.
 *
 * Pure Kotlin so every transition is host-tested; Android only reads and
 * applies [LinkFlags].
 */
object LinkState {

    enum class Phase { IDLE, PENDING, SENT }

    /**
     * A `CHALLENGE` this soon after adoption is the pairing desk still holding
     * the phone. Later ones may be the backend answering with the OLD key, so
     * they must not clear "LLAVE ENVIADA".
     */
    const val CONFIRM_WINDOW_MS = 120_000L

    /**
     * [windowRemainingMs] is the live monotonic window. A stale persisted
     * [LinkFlags.pending] with no window does not show PENDING.
     */
    fun phase(pending: Boolean, sent: Boolean, windowRemainingMs: Long): Phase = when {
        pending && windowRemainingMs > 0 -> Phase.PENDING
        sent -> Phase.SENT
        else -> Phase.IDLE
    }

    fun phase(flags: LinkFlags, windowRemainingMs: Long): Phase =
        phase(flags.pending, flags.sent, windowRemainingMs)

    /** Cancel is only offered while a window is actually open. */
    fun canCancel(phase: Phase): Boolean = phase == Phase.PENDING

    /**
     * "Vincular de nuevo" is offered whenever a key exists and no hand-off is
     * in flight. [Phase.SENT] offers it even when the cached key probe says
     * otherwise: an unconfirmed adoption is precisely when the person needs to
     * retry, and `hasKey` is only a cache.
     */
    fun canRelink(phase: Phase, hasKey: Boolean): Boolean = when (phase) {
        Phase.PENDING -> false
        Phase.SENT -> true
        Phase.IDLE -> hasKey
    }

    /** Opens a fresh window and supersedes any unconfirmed adoption. */
    fun beginWindow(flags: LinkFlags, untilMs: Long): LinkFlags = flags.copy(
        pending = true,
        pendingUntilMs = untilMs,
        sent = false,
        sentAtMs = 0L,
    )

    /** Closes the window without touching an unconfirmed adoption. */
    fun closeWindow(flags: LinkFlags): LinkFlags =
        flags.copy(pending = false, pendingUntilMs = 0L)

    /**
     * Cancel is [closeWindow] by definition: an adoption the backend never
     * confirmed must survive, so the card can still say "LLAVE ENVIADA" and
     * offer a re-link.
     */
    fun afterCancel(flags: LinkFlags): LinkFlags = closeWindow(flags)

    /**
     * The reader started adopting. Called BEFORE `keys.adopt`, so the
     * fail-safe worst case is a visible "LLAVE ENVIADA" rather than a silent
     * success. One edit also closes the window.
     */
    fun adoptionStarted(flags: LinkFlags, nowMs: Long): LinkFlags = flags.copy(
        pending = false,
        pendingUntilMs = 0L,
        sent = true,
        sentAtMs = nowMs,
    )

    /** The store refused the import: roll back the optimistic flag. */
    fun adoptionFailed(flags: LinkFlags): LinkFlags = flags.copy(sent = false, sentAtMs = 0L)

    /**
     * A successful `CHALLENGE` only proves adoption inside the desk window.
     * Outside it the same answer may come from the OLD key; keep SENT.
     */
    fun challengeConfirmsAdoption(
        sent: Boolean,
        sentAtMs: Long,
        nowMs: Long,
        windowMs: Long = CONFIRM_WINDOW_MS,
    ): Boolean = sent && sentAtMs > 0 && nowMs >= sentAtMs && nowMs - sentAtMs <= windowMs

    fun afterChallenge(flags: LinkFlags, windowRemainingMs: Long, nowMs: Long): LinkFlags {
        // A new window is open: this CHALLENGE was answered with the old key.
        if (phase(flags, windowRemainingMs) == Phase.PENDING) return flags
        return if (challengeConfirmsAdoption(flags.sent, flags.sentAtMs, nowMs)) {
            adoptionFailed(flags)
        } else {
            flags
        }
    }

    /**
     * Expiry of a persisted pending flag. A live monotonic window always wins,
     * so clock jumps cannot close it; [pendingUntilMs] only bounds a flag left
     * behind by a process restart, when the in-memory window is already gone.
     */
    fun persistedWindowExpired(
        pending: Boolean,
        windowRemainingMs: Long,
        pendingUntilMs: Long,
        wallNowMs: Long,
    ): Boolean = pending && windowRemainingMs <= 0 && wallNowMs >= pendingUntilMs
}
