package co.ecostation.app.speaker

import java.io.BufferedInputStream
import java.io.ByteArrayOutputStream
import java.io.EOFException
import java.io.IOException
import java.io.InputStream
import java.io.OutputStream
import java.net.InetSocketAddress
import java.net.Socket
import java.net.SocketTimeoutException
import java.net.URI
import java.security.MessageDigest
import java.security.SecureRandom

/**
 * Just enough RFC 6455 client to read EcoStation's realtime feed: plain
 * `ws://` on the LAN, text frames in, ping/pong/close out. No dependency,
 * pure JVM, host-tested against a local socket.
 *
 * Liveness: the server never pings, so the client does — every
 * [PING_EVERY_MS] — and declares the link dead after [DEAD_AFTER_MS] with no
 * inbound byte at all (a pong counts). That is what notices a hotspot client
 * that vanished without a FIN.
 */
class WsClient private constructor(private val socket: Socket) {

    private val input: InputStream = BufferedInputStream(socket.getInputStream())
    private val output: OutputStream = socket.getOutputStream()
    private val random = SecureRandom()

    @Volatile private var closed = false

    /**
     * Blocks, calling [onText] for every text frame, until the link closes or
     * goes quiet, or [isActive] turns false (checked every read tick). Returns
     * on a clean close or deactivation; throws IOException on a broken link.
     */
    fun readLoop(isActive: () -> Boolean = { true }, onText: (String) -> Unit) {
        var lastInbound = nowMs()
        var lastPing = lastInbound
        var fragments: ByteArrayOutputStream? = null
        var fragmentOpcode = 0
        while (!closed && isActive()) {
            val now = nowMs()
            if (now - lastInbound > DEAD_AFTER_MS) throw IOException("sin datos durante ${DEAD_AFTER_MS / 1000} s")
            if (now - lastPing >= PING_EVERY_MS) {
                send(OP_PING, ByteArray(0))
                lastPing = now
            }
            val frame = try {
                readFrame(input)
            } catch (e: SocketTimeoutException) {
                continue // poll tick: check liveness, maybe ping
            }
            lastInbound = nowMs()
            when (frame.opcode) {
                OP_TEXT, OP_BINARY -> {
                    if (frame.fin) {
                        if (fragments != null) throw IOException("mensaje nuevo dentro de uno fragmentado")
                        if (frame.opcode == OP_TEXT) onText(String(frame.payload, Charsets.UTF_8))
                    } else {
                        if (fragments != null) throw IOException("mensaje nuevo dentro de uno fragmentado")
                        val buffer = ByteArrayOutputStream(frame.payload.size * 2)
                        buffer.write(frame.payload)
                        fragments = buffer
                        fragmentOpcode = frame.opcode
                    }
                }
                OP_CONTINUATION -> {
                    val buffer = fragments ?: throw IOException("continuación sin mensaje iniciado")
                    if (buffer.size() + frame.payload.size > MAX_FRAME) {
                        throw IOException("mensaje fragmentado demasiado grande")
                    }
                    buffer.write(frame.payload)
                    if (frame.fin) {
                        if (fragmentOpcode == OP_TEXT) onText(String(buffer.toByteArray(), Charsets.UTF_8))
                        fragments = null
                        fragmentOpcode = 0
                    }
                }
                OP_PING -> send(OP_PONG, frame.payload)
                OP_CLOSE -> {
                    close()
                    return
                }
                else -> Unit // pong: nothing to do
            }
        }
    }

    private fun nowMs(): Long = System.nanoTime() / 1_000_000

    fun close() {
        if (closed) return
        closed = true
        try {
            send(OP_CLOSE, ByteArray(0))
        } catch (_: IOException) {
        }
        try {
            socket.close()
        } catch (_: IOException) {
        }
    }

    @Synchronized
    private fun send(opcode: Int, payload: ByteArray) {
        val mask = ByteArray(4).also { random.nextBytes(it) }
        output.write(encodeClientFrame(opcode, payload, mask))
        output.flush()
    }

    class Frame(val opcode: Int, val payload: ByteArray, val fin: Boolean = true)

    companion object {
        const val OP_CONTINUATION = 0x0
        const val OP_TEXT = 0x1
        const val OP_BINARY = 0x2
        const val OP_CLOSE = 0x8
        const val OP_PING = 0x9
        const val OP_PONG = 0xA
        private const val GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

        const val CONNECT_TIMEOUT_MS = 4_000
        const val READ_TICK_MS = 1_000
        const val PING_EVERY_MS = 5_000L
        const val DEAD_AFTER_MS = 12_000L

        /** Server frames above this are refused (the feed sends a few KB). */
        private const val MAX_FRAME = 1 shl 20

        /** Open `ws://host:port/path?query`, complete the upgrade, or throw. */
        fun connect(url: String): WsClient {
            val uri = URI(url)
            require(uri.scheme == "ws") { "solo se admite ws://" }
            val port = if (uri.port > 0) uri.port else 80
            val socket = Socket()
            try {
                socket.connect(InetSocketAddress(uri.host, port), CONNECT_TIMEOUT_MS)
                socket.soTimeout = CONNECT_TIMEOUT_MS
                socket.tcpNoDelay = true

                val key = base64(ByteArray(16).also { SecureRandom().nextBytes(it) })
                val target = (uri.rawPath?.ifEmpty { "/" } ?: "/") + (uri.rawQuery?.let { "?$it" } ?: "")
                val request = "GET $target HTTP/1.1\r\n" +
                    "Host: ${uri.host}:$port\r\n" +
                    "Upgrade: websocket\r\n" +
                    "Connection: Upgrade\r\n" +
                    "Sec-WebSocket-Key: $key\r\n" +
                    "Sec-WebSocket-Version: 13\r\n\r\n"
                socket.getOutputStream().apply { write(request.toByteArray(Charsets.US_ASCII)); flush() }

                val head = readHead(socket.getInputStream())
                val status = head.lineSequence().first()
                if (!status.startsWith("HTTP/1.1 101")) throw IOException("el servidor rechazó la conexión: $status")
                val accept = head.lineSequence()
                    .firstOrNull { it.startsWith("sec-websocket-accept:", ignoreCase = true) }
                    ?.substringAfter(':')?.trim()
                if (accept != acceptKey(key)) throw IOException("Sec-WebSocket-Accept no coincide")

                socket.soTimeout = READ_TICK_MS
                return WsClient(socket)
            } catch (e: Exception) {
                socket.close()
                throw if (e is IOException) e else IOException(e.message, e)
            }
        }

        fun acceptKey(key: String): String = base64(
            MessageDigest.getInstance("SHA-1").digest((key + GUID).toByteArray(Charsets.US_ASCII))
        )

        /** Standard padded Base64 (java.util.Base64 is API 26+, minSdk is 24). */
        fun base64(data: ByteArray): String {
            val table = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"
            val sb = StringBuilder((data.size + 2) / 3 * 4)
            for (i in data.indices step 3) {
                val n = (data[i].toInt() and 0xFF shl 16) or
                    ((if (i + 1 < data.size) data[i + 1].toInt() and 0xFF else 0) shl 8) or
                    (if (i + 2 < data.size) data[i + 2].toInt() and 0xFF else 0)
                sb.append(table[n shr 18 and 63]).append(table[n shr 12 and 63])
                sb.append(if (i + 1 < data.size) table[n shr 6 and 63] else '=')
                sb.append(if (i + 2 < data.size) table[n and 63] else '=')
            }
            return sb.toString()
        }

        /** Read the HTTP response head byte by byte (never past the blank line). */
        private fun readHead(input: InputStream): String {
            val buf = ByteArrayOutputStream()
            var tail = 0
            while (tail != 0x0D0A0D0A) {
                val b = input.read()
                if (b < 0) throw EOFException("la conexión se cerró durante el saludo")
                buf.write(b)
                tail = (tail shl 8) or b
                if (buf.size() > 8192) throw IOException("el saludo del servidor es demasiado grande")
            }
            return buf.toString(Charsets.US_ASCII.name())
        }

        /** Client frames are always masked (RFC 6455 §5.3), FIN set. */
        fun encodeClientFrame(opcode: Int, payload: ByteArray, mask: ByteArray): ByteArray {
            val out = ByteArrayOutputStream(payload.size + 14)
            out.write(0x80 or opcode)
            when {
                payload.size < 126 -> out.write(0x80 or payload.size)
                payload.size <= 0xFFFF -> {
                    out.write(0x80 or 126)
                    out.write(payload.size shr 8); out.write(payload.size and 0xFF)
                }
                else -> {
                    out.write(0x80 or 127)
                    for (shift in 56 downTo 0 step 8) out.write((payload.size.toLong() shr shift).toInt() and 0xFF)
                }
            }
            out.write(mask)
            payload.forEachIndexed { i, b -> out.write(b.toInt() xor mask[i % 4].toInt()) }
            return out.toByteArray()
        }

        /**
         * Read one frame. A read timeout before the first byte surfaces as
         * SocketTimeoutException (the poll tick); once a frame has started,
         * timeouts are retried until it completes.
         */
        fun readFrame(input: InputStream): Frame {
            val b0 = input.read()
            if (b0 < 0) throw EOFException("el servidor cerró la conexión")
            // Once a frame has started it must finish within the dead-link
            // window — a link that dies mid-frame must not hang the reader.
            val deadline = System.nanoTime() / 1_000_000 + DEAD_AFTER_MS
            val next = { readByte(input, deadline) }
            val b1 = next()
            val masked = b1 and 0x80 != 0
            var length = (b1 and 0x7F).toLong()
            if (length == 126L) {
                length = ((next() shl 8) or next()).toLong()
            } else if (length == 127L) {
                length = 0
                repeat(8) { length = (length shl 8) or next().toLong() }
                // RFC 6455 §5.2: the most significant bit MUST be 0. A negative
                // Long is exactly that bit set (or a size the JVM cannot hold).
                if (length < 0) throw IOException("longitud de trama no válida")
            }
            if (length < 0 || length > MAX_FRAME) throw IOException("trama demasiado grande: $length")
            val mask = if (masked) ByteArray(4) { next().toByte() } else null
            val payload = ByteArray(length.toInt())
            var off = 0
            while (off < payload.size) {
                val n = try {
                    input.read(payload, off, payload.size - off)
                } catch (e: SocketTimeoutException) {
                    if (System.nanoTime() / 1_000_000 > deadline) throw e
                    continue
                }
                if (n < 0) throw EOFException("la conexión se cerró a mitad de una trama")
                off += n
            }
            if (mask != null) for (i in payload.indices) payload[i] = (payload[i].toInt() xor mask[i % 4].toInt()).toByte()
            return Frame(b0 and 0x0F, payload, fin = b0 and 0x80 != 0)
        }

        private fun readByte(input: InputStream, deadline: Long): Int {
            while (true) {
                val b = try {
                    input.read()
                } catch (e: SocketTimeoutException) {
                    if (System.nanoTime() / 1_000_000 > deadline) throw IOException("la conexión se atascó a mitad de una trama")
                    continue
                }
                if (b < 0) throw EOFException("la conexión se cerró a mitad de una trama")
                return b
            }
        }
    }
}
