package co.ecostation.app.hce

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** The link hand-off state machine, independent of Android and the Keystore. */
class LinkStateTest {

    private val now = 10_000L
    private val future = now + EnrollmentWindow.DEFAULT_TTL_MS

    @Test
    fun pending_only_while_the_window_is_open_and_unexpired() {
        assertEquals(
            LinkState.Phase.PENDING,
            LinkState.phase(pending = true, pendingUntilMs = future, sent = false, nowMs = now),
        )
        assertEquals(
            LinkState.Phase.IDLE,
            LinkState.phase(pending = true, pendingUntilMs = future, sent = false, nowMs = future),
        )
    }

    @Test
    fun sent_survives_an_expired_pending_window_until_a_challenge_or_relink() {
        assertEquals(
            LinkState.Phase.SENT,
            LinkState.phase(pending = false, pendingUntilMs = future, sent = true, nowMs = now),
        )
        // A stale pending flag from an expired window must not hide the sent
        // state: the reader did adopt the key.
        assertEquals(
            LinkState.Phase.SENT,
            LinkState.phase(pending = true, pendingUntilMs = future, sent = true, nowMs = future),
        )
    }

    @Test
    fun a_fresh_window_wins_over_an_unconfirmed_adoption() {
        // Re-link after "llave enviada": the person is looking at the new
        // window; a CHALLENGE answered with the old key must not close it.
        assertEquals(
            LinkState.Phase.PENDING,
            LinkState.phase(pending = true, pendingUntilMs = future, sent = true, nowMs = now),
        )
    }

    @Test
    fun relink_is_offered_with_a_key_except_while_a_window_is_in_flight() {
        assertTrue(LinkState.canRelink(LinkState.Phase.IDLE, hasKey = true))
        assertFalse(LinkState.canRelink(LinkState.Phase.IDLE, hasKey = false))
        assertFalse(LinkState.canRelink(LinkState.Phase.PENDING, hasKey = true))
        // An adoption the reader has not confirmed is recoverable: offer retry.
        assertTrue(LinkState.canRelink(LinkState.Phase.SENT, hasKey = true))
        assertFalse(LinkState.canRelink(LinkState.Phase.SENT, hasKey = false))
    }
}
