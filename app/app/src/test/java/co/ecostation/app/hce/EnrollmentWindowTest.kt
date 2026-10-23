package co.ecostation.app.hce

import org.junit.Assert.*
import org.junit.Test

/** The one-time key hand-off window (ADR-004). */
class EnrollmentWindowTest {

    private val key = ByteArray(32) { (it + 1).toByte() }

    @Test
    fun releases_a_copy_once_then_closes() {
        val w = EnrollmentWindow(ttlMs = 1_000)
        w.open("ECO-A", key, nowMs = 100)
        assertEquals(1_000, w.remainingMs(100))

        val out = w.release("ECO-A", nowMs = 500)
        assertArrayEquals(key, out)
        out!!.fill(0)
        // The caller wiping its copy does not touch anything else, and the
        // window is closed after the single release.
        assertEquals(0, w.remainingMs(500))
        assertNull(w.release("ECO-A", nowMs = 501))
    }

    @Test
    fun keeps_its_own_copy_of_the_key() {
        val source = key.copyOf()
        val w = EnrollmentWindow()
        w.open("ECO-A", source, nowMs = 0)
        source.fill(0) // beginEnrollment wipes its array right after open()
        assertArrayEquals(key, w.release("ECO-A", nowMs = 1))
    }

    @Test
    fun expires() {
        val w = EnrollmentWindow(ttlMs = 1_000)
        w.open("ECO-A", key, nowMs = 0)
        assertEquals(1, w.remainingMs(999))
        assertNull(w.release("ECO-A", nowMs = 1_000))
        assertEquals(0, w.remainingMs(1_000))
    }

    @Test
    fun wrong_credential_gets_nothing_and_does_not_consume_the_window() {
        val w = EnrollmentWindow()
        w.open("ECO-A", key, nowMs = 0)
        assertNull(w.release("ECO-B", nowMs = 1))
        assertArrayEquals(key, w.release("ECO-A", nowMs = 2))
    }

    @Test
    fun reopening_replaces_and_close_wipes() {
        val w = EnrollmentWindow()
        w.open("ECO-A", key, nowMs = 0)
        val newer = ByteArray(32) { 7 }
        w.open("ECO-A", newer, nowMs = 1)
        assertArrayEquals(newer, w.release("ECO-A", nowMs = 2))

        w.open("ECO-A", key, nowMs = 3)
        w.close()
        assertEquals(0, w.remainingMs(3))
        assertNull(w.release("ECO-A", nowMs = 4))
    }

    @Test
    fun default_window_is_one_minute() {
        assertEquals(60_000L, EnrollmentWindow.DEFAULT_TTL_MS)
    }
}
