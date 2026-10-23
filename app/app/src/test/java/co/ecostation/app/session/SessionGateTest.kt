package co.ecostation.app.session

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/** The login/logout token race, deterministic on the host. */
class SessionGateTest {

    @Test
    fun a_stale_login_cannot_restore_a_token_after_logout() {
        val gate = SessionGate()
        var token: String? = null

        // The login thread snapshots the epoch, then the network call blocks.
        val loginEpoch = gate.begin()
        // Meanwhile logout runs to completion.
        val revoked = gate.end(read = { token }, clear = { token = null })
        assertEquals(null, revoked)

        // The login now tries to save: the epoch check and write are one step.
        val saved = gate.saveIfCurrent(loginEpoch, "1|stale") { token = it }
        assertFalse(saved)
        assertEquals(null, token)
    }

    @Test
    fun a_current_login_saves_and_logout_revokes_the_token_it_read() {
        val gate = SessionGate()
        var token: String? = null
        val login = gate.begin()

        assertTrue(gate.saveIfCurrent(login, "1|abc") { token = it })
        assertEquals("1|abc", token)

        val revoked = gate.end(read = { token }, clear = { token = null })
        assertEquals("1|abc", revoked)
        assertEquals(null, token)
        // Every earlier result is stale from now on.
        assertFalse(gate.saveIfCurrent(login, "1|late") { token = it })
        assertEquals(null, token)
    }

    @Test
    fun begin_invalidates_the_previous_action() {
        val gate = SessionGate()
        val old = gate.begin()
        val next = gate.begin()
        assertFalse(gate.isCurrent(old))
        assertTrue(gate.isCurrent(next))
    }

    @Test
    fun logout_is_atomic_against_a_concurrent_login_save() {
        val gate = SessionGate()
        var token: String? = null
        val login = gate.begin()
        val start = CountDownLatch(1)
        val done = CountDownLatch(1)
        var staleSaved: Boolean? = null

        val writer = Thread {
            start.await()
            staleSaved = gate.saveIfCurrent(login, "1|race") { token = it }
            done.countDown()
        }.apply { isDaemon = true; start() }

        start.countDown()
        gate.end(read = { token }, clear = { token = null })
        assertTrue(done.await(2, TimeUnit.SECONDS))
        writer.join(2_000)

        // Either the login wrote first (and logout cleared it) or it lost the
        // epoch; the final state is always signed out.
        assertEquals(null, token)
        assertFalse(staleSaved == true && token != null)
    }
}
