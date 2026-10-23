package co.ecostation.app.speaker

import co.ecostation.app.net.EcoEndpoint
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Test
import java.net.ServerSocket
import java.net.Socket
import kotlin.concurrent.thread

/** Laravel form login + `/realtime/token`, against a fake backend socket. */
class FeedSessionTest {

    @Test
    fun ticketLogsInOnceThenReusesTheSession() {
        FakeBackend().use { backend ->
            val session = FeedSession("station@ecostation.test", "password")
            val endpoint = EcoEndpoint("127.0.0.1", backend.port)
            val first = session.ticket(endpoint)
            assertEquals("ws://127.0.0.1:8081/app?token=7.999.sig%2B", first.wsUrl)
            assertEquals(1, backend.logins)
            session.ticket(endpoint)
            assertEquals("session reused, no second login", 1, backend.logins)
            // The server forgets the session -> exactly one re-login.
            backend.sessions.clear()
            session.ticket(endpoint)
            assertEquals(2, backend.logins)
        }
    }

    @Test
    fun wrongPasswordIsReportedAsLoginRejected() {
        FakeBackend().use { backend ->
            val session = FeedSession("station@ecostation.test", "nope")
            assertThrows(FeedSession.LoginRejected::class.java) {
                session.ticket(EcoEndpoint("127.0.0.1", backend.port))
            }
        }
    }

    @Test
    fun csrfTokenIsReadFromTheLaravelForm() {
        assertEquals("abc123", FeedSession.csrfToken("""<form><input type="hidden" name="_token" value="abc123" autocomplete="off"></form>"""))
        assertEquals("m1", FeedSession.csrfToken("""<meta name="csrf-token" content="m1">"""))
        assertNull(FeedSession.csrfToken("<html></html>"))
    }

    @Test
    fun ticket_refused_after_login_is_reported_as_login_rejected() {
        // Login succeeds (302 /password/change) but /realtime/token refuses
        // the session: a station account with a forced password change.
        FakeBackend(refuseFeed = true).use { backend ->
            val session = FeedSession("station@ecostation.test", "password")
            val e = assertThrows(FeedSession.LoginRejected::class.java) {
                session.ticket(EcoEndpoint("127.0.0.1", backend.port))
            }
            assertEquals(
                "la cuenta del parlante no puede abrir el feed: revisa su rol o cambia su contraseña en la web",
                e.message,
            )
        }
    }

    /**
     * The routes the bridge touches, with Laravel's observable behaviour,
     * over a raw socket (the android.jar test classpath has no
     * com.sun.net.httpserver).
     */
    private class FakeBackend(private val refuseFeed: Boolean = false) : AutoCloseable {
        private val socket = ServerSocket(0)
        val port get() = socket.localPort
        val sessions: MutableSet<String> = java.util.Collections.synchronizedSet(HashSet())
        @Volatile var logins = 0
        private var counter = 0

        init {
            thread(isDaemon = true) {
                try {
                    while (true) socket.accept().use { handle(it) }
                } catch (_: Exception) {
                }
            }
        }

        private fun handle(s: Socket) {
            val input = s.getInputStream()
            val head = StringBuilder()
            while (!head.endsWith("\r\n\r\n")) {
                val b = input.read()
                if (b < 0) return
                head.append(b.toChar())
            }
            val lines = head.lines()
            val (method, path) = lines.first().split(" ").let { it[0] to it[1] }
            fun header(name: String) =
                lines.firstOrNull { it.startsWith("$name:", ignoreCase = true) }?.substringAfter(':')?.trim()

            val length = header("Content-Length")?.toInt() ?: 0
            val body = String(ByteArray(length).also { buf ->
                var off = 0
                while (off < length) off += input.read(buf, off, length - off)
            })
            val cookie = header("Cookie").orEmpty()

            val extra = StringBuilder()
            var status = "200 OK"
            var content = ""
            when {
                path == "/login" && method == "GET" -> {
                    extra.append("Set-Cookie: ecostation_session=guest${counter++}; path=/; httponly\r\n")
                    content = """<input type="hidden" name="_token" value="csrf-1" autocomplete="off">"""
                }
                path == "/login" -> {
                    status = "302 Found"
                    val ok = "_token=csrf-1" in body && "password=password" in body &&
                        "ecostation_session=guest" in cookie
                    if (ok) {
                        logins++
                        val sid = "auth${counter++}"
                        sessions.add(sid)
                        extra.append("Set-Cookie: ecostation_session=$sid; path=/; httponly\r\n")
                        extra.append("Location: http://127.0.0.1:$port/station\r\n")
                    } else {
                        extra.append("Location: http://127.0.0.1:$port/login\r\n")
                    }
                }
                path == "/realtime/token" -> {
                    val sid = Regex("ecostation_session=([^;]+)").find(cookie)?.groupValues?.get(1)
                    when {
                        refuseFeed -> {
                            status = "302 Found"
                            extra.append("Location: http://127.0.0.1:$port/password/change\r\n")
                        }
                        sid == null || sid !in sessions -> status = "401 Unauthorized"
                        else -> content = """{"token":"7.999.sig+","expires_at":999,"url":"ws:\/\/127.0.0.1:8081\/app"}"""
                    }
                }
                else -> status = "404 Not Found"
            }
            val bytes = content.toByteArray()
            s.getOutputStream().apply {
                write("HTTP/1.1 $status\r\n${extra}Content-Length: ${bytes.size}\r\nConnection: close\r\n\r\n".toByteArray())
                write(bytes)
                flush()
            }
        }

        override fun close() = socket.close()
    }
}
