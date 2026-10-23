package co.ecostation.app.hce

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Opening a window is gated on a usable store, so link_failed is reachable. */
class CredentialRuntimeTest {

    private val now = 10_000L

    @Test
    fun an_unavailable_store_does_not_open_a_window() {
        val broken = InMemoryCredentialKeyStore().apply { available = false }
        try {
            assertFalse(CredentialRuntime.beginEnrollment("ECO-TESTTESTTEST", broken) { now })
            assertEquals(0L, CredentialRuntime.enrollment.remainingMs(now))
        } finally {
            CredentialRuntime.enrollment.close()
        }
    }

    @Test
    fun an_available_store_opens_the_window_for_the_full_ttl() {
        val good = InMemoryCredentialKeyStore()
        try {
            assertTrue(CredentialRuntime.beginEnrollment("ECO-TESTTESTTEST", good) { now })
            assertEquals(EnrollmentWindow.DEFAULT_TTL_MS, CredentialRuntime.enrollment.remainingMs(now))
        } finally {
            CredentialRuntime.enrollment.close()
        }
    }
}
