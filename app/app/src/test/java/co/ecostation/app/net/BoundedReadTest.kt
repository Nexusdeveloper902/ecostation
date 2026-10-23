package co.ecostation.app.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.ByteArrayInputStream
import java.io.IOException
import java.io.InputStream
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

class BoundedReadTest {

    @Test
    fun reads_up_to_the_budget() {
        assertEquals("hola", BoundedRead.read(ByteArrayInputStream("hola".toByteArray()), 4))
        assertEquals("", BoundedRead.read(ByteArrayInputStream(ByteArray(0)), 4))
        assertEquals("", BoundedRead.read(null, 4))
    }

    @Test
    fun aborts_one_byte_past_the_budget_in_spanish() {
        val e = assertThrows(IOException::class.java) {
            BoundedRead.read(ByteArrayInputStream(ByteArray(65)), 64)
        }
        assertEquals(true, e.message!!.contains("supera el límite"))
        // Exactly the budget still fits.
        assertEquals(64 * 1024, BoundedRead.read(ByteArrayInputStream(ByteArray(64 * 1024)), BoundedRead.SMALL_MAX).length)
    }

    @Test
    fun a_drip_feeding_stream_is_cut_by_the_time_budget() {
        val started = System.nanoTime()
        val e = assertThrows(IOException::class.java) {
            BoundedRead.read(EndlessDripStream(), BoundedRead.SMALL_MAX, timeoutMs = 150L)
        }
        val elapsedMs = (System.nanoTime() - started) / 1_000_000L
        assertTrue(e.message!!, e.message!!.contains("límite de tiempo"))
        assertTrue("a drip-fed body must not hold the read for hours (took $elapsedMs ms)", elapsedMs < 2_000L)
    }

    @Test
    fun a_stream_that_never_returns_is_closed_by_the_deadline() {
        val stream = BlockingStream()
        val started = System.nanoTime()
        val e = assertThrows(IOException::class.java) {
            BoundedRead.read(stream, BoundedRead.SMALL_MAX, timeoutMs = 150L)
        }
        val elapsedMs = (System.nanoTime() - started) / 1_000_000L
        assertTrue(e.message!!, e.message!!.contains("límite de tiempo"))
        assertTrue("close() must unblock the read (took $elapsedMs ms)", elapsedMs < 2_000L)
        assertTrue(stream.closed.await(1, TimeUnit.SECONDS))
    }

    /** Never signals EOF; each chunk takes 5 ms. */
    private class EndlessDripStream : InputStream() {
        override fun read(): Int {
            Thread.sleep(5)
            return 'a'.code
        }

        override fun read(b: ByteArray, off: Int, len: Int): Int {
            if (len == 0) return 0
            Thread.sleep(5)
            b[off] = 'a'.code.toByte()
            return 1
        }
    }

    /** Blocks inside read() until the watchdog closes it. */
    private class BlockingStream : InputStream() {
        val closed = CountDownLatch(1)
        private val release = CountDownLatch(1)

        override fun read(): Int {
            release.await()
            throw IOException("cerrado por el vencimiento")
        }

        override fun read(b: ByteArray, off: Int, len: Int): Int {
            release.await()
            throw IOException("cerrado por el vencimiento")
        }

        override fun close() {
            closed.countDown()
            release.countDown()
        }
    }
}
