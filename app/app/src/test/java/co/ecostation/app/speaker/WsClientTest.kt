package co.ecostation.app.speaker

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.io.IOException
import java.net.ServerSocket
import java.net.Socket
import java.util.concurrent.CopyOnWriteArrayList
import kotlin.concurrent.thread

/** The hand-rolled RFC 6455 client against a real local socket. */
class WsClientTest {

    @Test
    fun acceptKeyMatchesTheRfcVector() {
        // RFC 6455 §1.3 worked example.
        assertEquals("s3pPLMBiTxaQ9kYGzzhZRbK+xOo=", WsClient.acceptKey("dGhlIHNhbXBsZSBub25jZQ=="))
        assertEquals("", WsClient.base64(ByteArray(0)))
        assertEquals("Zm9vYmFy", WsClient.base64("foobar".toByteArray()))
        assertEquals("Zm9vYg==", WsClient.base64("foob".toByteArray()))
    }

    @Test
    fun clientFramesAreMaskedAndRoundTrip() {
        val mask = byteArrayOf(1, 2, 3, 4)
        for (size in listOf(0, 5, 125, 126, 70_000)) {
            val payload = ByteArray(size) { (it % 251).toByte() }
            val frame = WsClient.encodeClientFrame(WsClient.OP_TEXT, payload, mask)
            assertEquals(0x81, frame[0].toInt() and 0xFF)
            assertTrue(frame[1].toInt() and 0x80 != 0)
            val decoded = WsClient.readFrame(frame.inputStream())
            assertEquals(WsClient.OP_TEXT, decoded.opcode)
            assertArrayEquals(payload, decoded.payload)
        }
    }

    @Test
    fun readsTapFramesAnswersPingsAndReportsServerDrop() {
        FakeWsServer().use { server ->
            server.onClient = { s ->
                val out = s.getOutputStream()
                out.write(serverFrame(0x1, """{"type":"hello","events":[]}"""))
                out.write(serverFrame(0x9, "hi"))
                repeat(3) { i ->
                    out.write(serverFrame(0x1, """{"type":"tap","event":{"id":$i,"served":${i != 1}}}"""))
                }
                out.flush()
                // Expect our pong before hanging up.
                val pong = WsClient.readFrame(s.getInputStream())
                server.pongs.add(pong.opcode to String(pong.payload))
                s.close()
            }
            val ws = WsClient.connect("ws://127.0.0.1:${server.port}/app?token=abc")
            val cues = CopyOnWriteArrayList<FeedbackCue>()
            try {
                ws.readLoop { text -> FeedbackCue.fromFrame(text)?.let(cues::add) }
                fail("a server hang-up must surface as IOException")
            } catch (e: IOException) {
                // Expected: the bridge turns this into a reconnect.
            }
            assertEquals(listOf(FeedbackCue.ACCEPTED, FeedbackCue.REJECTED, FeedbackCue.ACCEPTED), cues)
            assertEquals(listOf(WsClient.OP_PONG to "hi"), server.pongs)
            assertTrue(server.requests.single().startsWith("GET /app?token=abc HTTP/1.1"))
        }
    }

    @Test
    fun refusedUpgradeFailsTheConnect() {
        FakeWsServer(status = "HTTP/1.1 401 Unauthorized").use { server ->
            try {
                WsClient.connect("ws://127.0.0.1:${server.port}/app?token=bad")
                fail("401 must not connect")
            } catch (e: IOException) {
                assertTrue(e.message!!.contains("401"))
            }
        }
    }

    @Test(timeout = 30_000)
    fun silentLinkIsDeclaredDeadAndPingsAreSent() {
        FakeWsServer().use { server ->
            // A peer that never answers (a vanished hotspot client).
            server.onClient = { s ->
                val input = s.getInputStream()
                try {
                    while (true) server.pongs.add(WsClient.readFrame(input).let { it.opcode to "" })
                } catch (_: Exception) {
                }
            }
            val ws = WsClient.connect("ws://127.0.0.1:${server.port}/app")
            val started = System.currentTimeMillis()
            try {
                ws.readLoop { }
                fail("silence must end the loop with IOException")
            } catch (e: IOException) {
                val took = System.currentTimeMillis() - started
                assertTrue("took $took ms", took in WsClient.DEAD_AFTER_MS..WsClient.DEAD_AFTER_MS + 3_000)
            }
            assertTrue("client pinged", server.pongs.any { it.first == WsClient.OP_PING })
        }
    }

    @Test(timeout = 10_000)
    fun deactivationEndsTheLoopQuietly() {
        FakeWsServer().use { server ->
            server.onClient = { s ->
                Thread.sleep(8_000)
                s.close()
            }
            val ws = WsClient.connect("ws://127.0.0.1:${server.port}/app")
            val stopAt = System.currentTimeMillis() + 1_500
            ws.readLoop(isActive = { System.currentTimeMillis() < stopAt }) { }
            ws.close()
        }
    }

    @Test
    fun continuationFramesAreReassembledIntoOneTextMessage() {
        FakeWsServer().use { server ->
            server.onClient = { s ->
                val out = s.getOutputStream()
                out.write(serverFrame(0x1, """{"type":"hello",""", fin = false))
                out.write(serverFrame(0x0, """"events":[]}""", fin = true))
                out.flush()
                Thread.sleep(200)
                s.close()
            }
            val ws = WsClient.connect("ws://127.0.0.1:${server.port}/app")
            val messages = CopyOnWriteArrayList<String>()
            try {
                ws.readLoop { messages.add(it) }
            } catch (_: IOException) {
                // The fake server hangs up after the fragmented message.
            }
            assertEquals(listOf("""{"type":"hello","events":[]}"""), messages)
        }
    }

    @Test
    fun a64BitLengthWithTheHighBitSetIsRejected() {
        val frame = byteArrayOf(0x82.toByte(), 0x7F) + ByteArray(8) { 0xFF.toByte() }
        try {
            WsClient.readFrame(frame.inputStream())
            fail("a negative 64-bit length must not allocate")
        } catch (e: IOException) {
            assertTrue(e.message!!.contains("longitud"))
        }
    }

    // ------------------------------------------------------------- helpers

    private fun serverFrame(opcode: Int, text: String, fin: Boolean = true): ByteArray {
        val p = text.toByteArray()
        require(p.size < 126)
        return byteArrayOf(((if (fin) 0x80 else 0x00) or opcode).toByte(), p.size.toByte()) + p
    }

    private class FakeWsServer(private val status: String = "HTTP/1.1 101 Switching Protocols") : AutoCloseable {
        private val socket = ServerSocket(0)
        val port get() = socket.localPort
        val requests = CopyOnWriteArrayList<String>()
        val pongs = CopyOnWriteArrayList<Pair<Int, String>>()
        var onClient: (Socket) -> Unit = { }

        init {
            thread(isDaemon = true) {
                try {
                    while (true) {
                        val s = socket.accept()
                        thread(isDaemon = true) { serve(s) }
                    }
                } catch (_: Exception) {
                }
            }
        }

        private fun serve(s: Socket) {
            try {
                val input = s.getInputStream()
                val head = StringBuilder()
                while (!head.endsWith("\r\n\r\n")) head.append(input.read().toChar())
                requests.add(head.toString())
                val key = head.lines().first { it.startsWith("Sec-WebSocket-Key:") }.substringAfter(':').trim()
                val response = if (status.contains("101")) {
                    "$status\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${WsClient.acceptKey(key)}\r\n\r\n"
                } else {
                    "$status\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"
                }
                s.getOutputStream().write(response.toByteArray())
                if (status.contains("101")) onClient(s) else s.close()
            } catch (_: Exception) {
            }
        }

        override fun close() {
            socket.close()
        }
    }
}
