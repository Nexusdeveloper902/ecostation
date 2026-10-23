package co.ecostation.app.net

import java.io.ByteArrayOutputStream
import java.io.IOException
import java.io.InputStream
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Reads a response body without trusting its size OR its pace. A LAN server
 * (or anything that answers on the discovered address) must not be able to
 * make the app buffer an unbounded amount of memory, nor drip-feed a body
 * forever to hold discovery.
 *
 * [maxBytes] counts bytes, not characters, so a runaway multibyte body is
 * still cut at the same budget. [timeoutMs] > 0 adds an overall wall-clock
 * budget: a watchdog closes the stream at the deadline and the read loop also
 * checks between chunks, so neither a slow nor a never-ending stream can
 * outlive it.
 */
object BoundedRead {

    /** Manifests and login HTML pages are a few KB; 64 KB is generous. */
    const val SMALL_MAX = 64 * 1024

    /** JSON responses (feed token, balance, history). */
    const val JSON_MAX = 1 shl 20

    /** Overall budget for a manifest probe during discovery / Settings test. */
    const val MANIFEST_TIMEOUT_MS = 2_000L

    /**
     * One daemon thread for every bounded read: the watchdog only closes the
     * stream, so it never blocks on the caller.
     */
    private val watchdog = Executors.newSingleThreadScheduledExecutor { runnable ->
        Thread(runnable, "eco-read-deadline").apply { isDaemon = true }
    }

    fun read(stream: InputStream?, maxBytes: Int, timeoutMs: Long = 0L): String {
        if (stream == null) return ""
        if (timeoutMs <= 0L) return readBody(stream, maxBytes) { false }

        val expired = AtomicBoolean(false)
        val task = watchdog.schedule(
            {
                expired.set(true)
                try {
                    stream.close()
                } catch (_: Exception) {
                    // The read side reports the deadline; closing is best effort.
                }
            },
            timeoutMs,
            TimeUnit.MILLISECONDS,
        )
        try {
            return readBody(stream, maxBytes) { expired.get() }
        } catch (e: IOException) {
            if (expired.get()) throw deadline(timeoutMs, e)
            throw e
        } finally {
            task.cancel(false)
        }
    }

    private inline fun readBody(
        stream: InputStream,
        maxBytes: Int,
        isExpired: () -> Boolean,
    ): String {
        val out = ByteArrayOutputStream(minOf(maxBytes, 8 * 1024))
        val buffer = ByteArray(8 * 1024)
        var total = 0
        while (true) {
            if (isExpired()) throw IOException("la lectura supera el límite de tiempo")
            val read = stream.read(buffer)
            if (isExpired()) throw IOException("la lectura supera el límite de tiempo")
            if (read < 0) break
            total += read
            if (total > maxBytes) {
                throw IOException("la respuesta del servidor supera el límite de $maxBytes bytes")
            }
            out.write(buffer, 0, read)
        }
        return out.toString(Charsets.UTF_8.name())
    }

    private fun deadline(timeoutMs: Long, cause: IOException? = null): IOException =
        IOException("la lectura supera el límite de tiempo de $timeoutMs ms", cause)
}
