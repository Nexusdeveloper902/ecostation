package co.ecostation.app.net

import java.io.ByteArrayOutputStream
import java.io.IOException
import java.io.InputStream

/**
 * Reads a response body without trusting its size. A LAN server (or anything
 * that answers on the discovered address) must not be able to make the app
 * buffer an unbounded amount of memory.
 *
 * [maxBytes] counts bytes, not characters, so a runaway multibyte body is
 * still cut at the same budget.
 */
object BoundedRead {

    /** Manifests and login HTML pages are a few KB; 64 KB is generous. */
    const val SMALL_MAX = 64 * 1024

    /** JSON responses (feed token, balance, history). */
    const val JSON_MAX = 1 shl 20

    fun read(stream: InputStream?, maxBytes: Int): String {
        if (stream == null) return ""
        val out = ByteArrayOutputStream(minOf(maxBytes, 8 * 1024))
        val buffer = ByteArray(8 * 1024)
        var total = 0
        while (true) {
            val read = stream.read(buffer)
            if (read < 0) break
            total += read
            if (total > maxBytes) {
                throw IOException("la respuesta del servidor supera el límite de $maxBytes bytes")
            }
            out.write(buffer, 0, read)
        }
        return out.toString(Charsets.UTF_8.name())
    }
}
