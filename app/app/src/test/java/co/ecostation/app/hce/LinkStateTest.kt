package co.ecostation.app.hce

import co.ecostation.app.store.LinkFlags
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** The link hand-off state machine, independent of Android and the Keystore. */
class LinkStateTest {

    private val wallNow = 1_000_000L
    private val future = wallNow + EnrollmentWindow.DEFAULT_TTL_MS
    private val open = LinkFlags(pending = true, pendingUntilMs = future)

    @Test
    fun pending_only_while_the_monotonic_window_is_open() {
        assertEquals(
            LinkState.Phase.PENDING,
            LinkState.phase(pending = true, sent = false, windowRemainingMs = 1L),
        )
        assertEquals(
            LinkState.Phase.IDLE,
            LinkState.phase(pending = true, sent = false, windowRemainingMs = 0L),
        )
    }

    @Test
    fun a_wall_clock_jump_neither_hides_an_open_window_nor_leaves_a_zero_countdown() {
        // Forward jump past the persisted deadline: the live monotonic window
        // still wins, so the card keeps VINCULANDO with its real seconds.
        assertEquals(LinkState.Phase.PENDING, LinkState.phase(open, windowRemainingMs = 42_000L))
        assertFalse(
            LinkState.persistedWindowExpired(
                pending = true,
                windowRemainingMs = 42_000L,
                pendingUntilMs = future,
                wallNowMs = wallNow + 10 * 60_000L,
            ),
        )
        // Backward jump: same, the wall clock never closes a live window.
        assertEquals(LinkState.Phase.PENDING, LinkState.phase(open, windowRemainingMs = 59_000L))
        assertFalse(
            LinkState.persistedWindowExpired(
                pending = true,
                windowRemainingMs = 59_000L,
                pendingUntilMs = future,
                wallNowMs = wallNow - 10 * 60_000L,
            ),
        )
    }

    @Test
    fun only_a_gone_window_uses_the_persisted_deadline_after_a_restart() {
        // Process restart: no in-memory window, deadline still in the future.
        assertFalse(
            LinkState.persistedWindowExpired(
                pending = true,
                windowRemainingMs = 0L,
                pendingUntilMs = future,
                wallNowMs = wallNow,
            ),
        )
        // Same flag once the persisted bound passes.
        assertTrue(
            LinkState.persistedWindowExpired(
                pending = true,
                windowRemainingMs = 0L,
                pendingUntilMs = future,
                wallNowMs = future,
            ),
        )
        // No persisted flag: nothing to expire.
        assertFalse(
            LinkState.persistedWindowExpired(
                pending = false,
                windowRemainingMs = 0L,
                pendingUntilMs = future,
                wallNowMs = future,
            ),
        )
    }

    @Test
    fun sent_survives_an_expired_pending_window_until_confirmed_or_relinked() {
        assertEquals(
            LinkState.Phase.SENT,
            LinkState.phase(pending = false, sent = true, windowRemainingMs = 0L),
        )
        // A stale pending flag from an expired window must not hide the sent
        // state: the reader did adopt the key.
        assertEquals(
            LinkState.Phase.SENT,
            LinkState.phase(pending = true, sent = true, windowRemainingMs = 0L),
        )
    }

    @Test
    fun a_fresh_window_wins_over_an_unconfirmed_adoption() {
        // Re-link after "llave enviada": the person is looking at the new
        // window; a CHALLENGE answered with the old key must not close it.
        assertEquals(
            LinkState.Phase.PENDING,
            LinkState.phase(pending = true, sent = true, windowRemainingMs = 1L),
        )
    }

    @Test
    fun cancel_closes_the_window_and_never_discards_the_unconfirmed_adoption() {
        val adopted = LinkFlags(pending = true, pendingUntilMs = future, sent = true, sentAtMs = wallNow)
        val cancelled = LinkState.afterCancel(adopted)
        assertFalse(cancelled.pending)
        assertEquals(0L, cancelled.pendingUntilMs)
        // This is the HIGH finding: cancel in LLAVE ENVIADA must not lose sent.
        assertTrue(cancelled.sent)
        assertEquals(wallNow, cancelled.sentAtMs)
        assertEquals(LinkState.Phase.SENT, LinkState.phase(cancelled, windowRemainingMs = 0L))
    }

    @Test
    fun only_a_desk_window_challenge_clears_sent() {
        val sent = LinkFlags(sent = true, sentAtMs = wallNow)
        // Inside two minutes: the pairing desk proves the new key works.
        assertTrue(LinkState.challengeConfirmsAdoption(sent.sent, sent.sentAtMs, wallNow + 1_000L))
        val confirmed = LinkState.afterChallenge(sent, windowRemainingMs = 0L, nowMs = wallNow + 1_000L)
        assertFalse(confirmed.sent)
        assertEquals(0L, confirmed.sentAtMs)

        // Later, the backend may still answer with the OLD key: keep SENT.
        assertFalse(
            LinkState.challengeConfirmsAdoption(
                sent.sent,
                sent.sentAtMs,
                wallNow + LinkState.CONFIRM_WINDOW_MS + 1L,
            ),
        )
        val kept = LinkState.afterChallenge(
            sent,
            windowRemainingMs = 0L,
            nowMs = wallNow + LinkState.CONFIRM_WINDOW_MS + 1L,
        )
        assertTrue(kept.sent)
        // A backward clock jump cannot make an old adoption look fresh.
        assertFalse(LinkState.challengeConfirmsAdoption(sent.sent, sent.sentAtMs, wallNow - 1L))
        assertTrue(LinkState.afterChallenge(sent, windowRemainingMs = 0L, nowMs = wallNow - 1L).sent)
    }

    @Test
    fun a_challenge_inside_a_new_window_keeps_both_flags_alone() {
        val both = LinkFlags(pending = true, pendingUntilMs = future, sent = true, sentAtMs = wallNow)
        assertEquals(both, LinkState.afterChallenge(both, windowRemainingMs = 10_000L, nowMs = wallNow + 1_000L))
    }

    @Test
    fun an_adoption_is_flagged_first_and_rolled_back_only_on_refusal() {
        val started = LinkState.adoptionStarted(open, wallNow)
        assertFalse(started.pending)
        assertEquals(0L, started.pendingUntilMs)
        assertTrue(started.sent)
        assertEquals(wallNow, started.sentAtMs)

        val failed = LinkState.adoptionFailed(started)
        assertFalse(failed.sent)
        assertEquals(0L, failed.sentAtMs)
    }

    @Test
    fun relink_is_offered_with_a_key_except_while_a_window_is_in_flight() {
        assertTrue(LinkState.canRelink(LinkState.Phase.IDLE, hasKey = true))
        assertFalse(LinkState.canRelink(LinkState.Phase.IDLE, hasKey = false))
        assertFalse(LinkState.canRelink(LinkState.Phase.PENDING, hasKey = true))
        // An adoption the reader has not confirmed is recoverable: offer retry
        // even if the cached key probe transiently says false.
        assertTrue(LinkState.canRelink(LinkState.Phase.SENT, hasKey = true))
        assertTrue(LinkState.canRelink(LinkState.Phase.SENT, hasKey = false))
    }

    @Test
    fun cancel_is_only_offered_while_a_window_is_open() {
        assertTrue(LinkState.canCancel(LinkState.Phase.PENDING))
        assertFalse(LinkState.canCancel(LinkState.Phase.SENT))
        assertFalse(LinkState.canCancel(LinkState.Phase.IDLE))
    }
}
