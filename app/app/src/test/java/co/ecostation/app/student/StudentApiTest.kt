package co.ecostation.app.student

import co.ecostation.app.net.EcoEndpoint
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test
import java.net.ServerSocket
import java.net.Socket
import kotlin.concurrent.thread

/**
 * The student API client against a local HTTP server. The student API shape
 * is the web plan's contract: token, password change, logout, balance and
 * history, all UTF-8 JSON with `Accept: application/json`.
 */
class StudentApiTest {

    private val fake = FakeHttp()
    private val api = StudentApi(EcoEndpoint("127.0.0.1", fake.port))

    @After
    fun stopServer() = fake.close()

    @Test
    fun login_posts_json_and_returns_token_flag_and_student() {
        fake.on(
            "POST /api/v1/auth/token",
            200,
            """{"token":"5|abc","must_change_password":false,"student":{"id":3,"name":"Ana Martínez","class_name":"5° A"},"extra":1}""",
        )
        val r = api.login("ana@ecostation.test", "clave ñ")
        assertEquals("5|abc", r.token)
        assertFalse(r.mustChangePassword)
        assertEquals(StudentInfo(3, "Ana Martínez", "5° A"), r.student)
        val req = JSONObject(fake.lastBody)
        assertEquals("ana@ecostation.test", req.getString("email"))
        assertEquals("clave ñ", req.getString("password"))
        assertEquals("EcoStation Android", req.getString("device_name"))
        assertEquals("application/json", fake.lastHeader("Accept"))
    }

    @Test
    fun login_reports_must_change_password() {
        fake.on(
            "POST /api/v1/auth/token",
            200,
            """{"token":"t","must_change_password":true,"student":{"id":3,"name":"Ana","class_name":null}}""",
        )
        val r = api.login("a", "b")
        assertTrue(r.mustChangePassword)
        assertNull(r.student.className)
    }

    @Test
    fun login_errors_map_to_kinds() {
        val cases = listOf(
            Triple(422, "{}", ApiException.Kind.BAD_CREDENTIALS),
            Triple(429, "{}", ApiException.Kind.THROTTLED),
            Triple(500, "oops", ApiException.Kind.SERVER),
        )
        for ((status, body, kind) in cases) {
            fake.on("POST /api/v1/auth/token", status, body)
            assertEquals(kind, assertThrows(ApiException::class.java) { api.login("a", "b") }.kind)
        }
    }

    @Test
    fun password_change_sends_bearer_and_maps_422() {
        fake.on("PUT /api/v1/auth/password", 204, "")
        api.changePassword("5|abc", "vieja", "nueva-clave-1", "nueva-clave-1")
        val req = JSONObject(fake.lastBody)
        assertEquals("vieja", req.getString("current_password"))
        assertEquals("nueva-clave-1", req.getString("password"))
        assertEquals("nueva-clave-1", req.getString("password_confirmation"))
        assertEquals("Bearer 5|abc", fake.lastHeader("Authorization"))

        fake.on(
            "PUT /api/v1/auth/password",
            422,
            """{"message":"x","errors":{"current_password":["La contraseña actual es incorrecta."]}}""",
        )
        val e = assertThrows(ApiException::class.java) { api.changePassword("t", "x", "y", "y") }
        assertEquals(ApiException.Kind.VALIDATION, e.kind)
        assertEquals("La contraseña actual es incorrecta.", e.message)
    }

    @Test
    fun logout_sends_delete_and_tolerates_401() {
        fake.on("DELETE /api/v1/auth/token", 204, "")
        api.logout("5|abc")
        assertEquals("Bearer 5|abc", fake.lastHeader("Authorization"))

        fake.on("DELETE /api/v1/auth/token", 401, "{}")
        api.logout("5|abc")
    }

    @Test
    fun balance_sends_bearer_and_parses_utf8_rank_and_null_rank() {
        fake.on(
            "GET /api/v1/me/balance",
            200,
            """{"student":{"id":3,"name":"Ana Martínez","class_name":"5° A"},"balance":120,"rank":3,"extra":1}""",
        )
        assertEquals(Balance(StudentInfo(3, "Ana Martínez", "5° A"), 120, 3), api.balance("5|abc"))
        assertEquals("Bearer 5|abc", fake.lastHeader("Authorization"))

        fake.on(
            "GET /api/v1/me/balance",
            200,
            """{"student":{"id":3,"name":"Ana","class_name":null},"balance":0,"rank":null}""",
        )
        assertNull(api.balance("t").rank)
    }

    @Test
    fun balance_and_history_error_kinds() {
        for (path in listOf("/api/v1/me/balance", "/api/v1/me/history")) {
            fun kind() = assertThrows(ApiException::class.java) {
                if (path.endsWith("balance")) api.balance("x") else api.history("x")
            }.kind
            fake.on("GET $path", 401, """{"message":"Unauthenticated."}""")
            assertEquals(ApiException.Kind.UNAUTHORIZED, kind())
            fake.on("GET $path", 403, """{"message":"x"}""")
            assertEquals(ApiException.Kind.PASSWORD_CHANGE_REQUIRED, kind())
            fake.on("GET $path", 404, "{}")
            assertEquals(ApiException.Kind.NOT_LINKED, kind())
        }
    }

    @Test
    fun unauthorized_is_its_own_error_kind() {
        fake.on("GET /api/v1/me/balance", 401, """{"message":"Unauthenticated."}""")
        assertEquals(ApiException.Kind.UNAUTHORIZED, assertThrows(ApiException::class.java) { api.balance("x") }.kind)
    }

    @Test
    fun a_token_401_on_password_change_is_unauthorized() {
        fake.on("PUT /api/v1/auth/password", 401, """{"message":"Unauthenticated."}""")
        assertEquals(
            ApiException.Kind.UNAUTHORIZED,
            assertThrows(ApiException::class.java) { api.changePassword("t", "a", "b", "b") }.kind,
        )
    }

    @Test
    fun history_tolerates_null_unknown_and_extra_fields() {
        fake.on(
            "GET /api/v1/me/history",
            200,
            """{"current_page":1,"next_page_url":"http://x/api/v1/me/history?page=2","data":[
                {"deposit_id":9,"occurred_at":"2026-10-23T09:15:00-05:00","material_class":"plastic","points_awarded":10,"is_recyclable":true,"photo":"x"},
                {"deposit_id":8,"occurred_at":"2026-10-22T14:00:00-05:00","material_class":null,"points_awarded":0,"is_recyclable":false},
                {"deposit_id":7,"occurred_at":"2026-10-21T08:00:00-05:00","material_class":"cartón","points_awarded":0}]}""",
        )
        val h = api.history("t")
        assertEquals(3, h.items.size)
        assertTrue(h.hasMore)
        assertEquals(Deposit(9, "2026-10-23T09:15:00-05:00", "plastic", 10, true), h.items[0])
        assertEquals("/api/v1/me/history?page=1", fake.lastPath)
        assertNull(h.items[1].materialClass)
        assertEquals("Otro", materialLabel(h.items[1].materialClass))
        assertEquals("Otro", materialLabel(h.items[2].materialClass))

        fake.on("GET /api/v1/me/history", 200, """{"data":[],"next_page_url":null}""")
        val e = api.history("t", 2)
        assertTrue(e.items.isEmpty())
        assertFalse(e.hasMore)
        assertTrue(fake.lastPath.endsWith("page=2"))
    }

    @Test
    fun labels_and_times_are_spanish_and_bogota() {
        assertEquals("Vidrio", materialLabel("glass"))
        assertEquals("23/10 09:15", displayTime("2026-10-23T14:15:00Z"))
        assertEquals("23/10 09:15", displayTime("2026-10-23T09:15:00-05:00"))
        assertEquals("ayer", displayTime("ayer"))
    }

    @Test
    fun malformed_success_body_is_a_server_error() {
        fake.on("GET /api/v1/me/balance", 200, "<html>")
        assertEquals(ApiException.Kind.SERVER, assertThrows(ApiException::class.java) { api.balance("t") }.kind)
    }

    /**
     * ~40-line HTTP/1.1 fake: routes on `"METHOD path"` (query ignored for
     * routing, kept in [lastPath]) and records the last request.
     */
    private class FakeHttp : AutoCloseable {
        private val server = ServerSocket(0)
        val port: Int get() = server.localPort

        private val routes = HashMap<String, Pair<Int, String>>()
        private val headers = HashMap<String, String>()

        @Volatile
        var lastPath = ""

        @Volatile
        var lastBody = ""

        init {
            thread(isDaemon = true) {
                try {
                    while (true) server.accept().use { handle(it) }
                } catch (_: Exception) {
                }
            }
        }

        @Synchronized
        fun on(route: String, status: Int, body: String) {
            routes[route] = status to body
        }

        fun lastHeader(name: String): String? = synchronized(headers) { headers[name.lowercase()] }

        private fun handle(s: Socket) {
            val input = s.getInputStream()
            val head = StringBuilder()
            while (!head.endsWith("\r\n\r\n")) {
                val b = input.read()
                if (b < 0) return
                head.append(b.toChar())
            }
            val lines = head.lines()
            val (method, target) = lines.first().split(" ").let { it[0] to it[1] }
            val path = target.substringBefore('?')
            val length = lines.firstOrNull { it.startsWith("Content-Length:", ignoreCase = true) }
                ?.substringAfter(':')?.trim()?.toInt() ?: 0
            val body = String(
                ByteArray(length).also { buf ->
                    var off = 0
                    while (off < length) off += input.read(buf, off, length - off)
                },
                Charsets.UTF_8,
            )
            synchronized(headers) {
                headers.clear()
                lines.drop(1).forEach { line ->
                    val i = line.indexOf(':')
                    if (i > 0) headers[line.substring(0, i).trim().lowercase()] = line.substring(i + 1).trim()
                }
            }
            lastPath = target
            lastBody = body

            val (status, content) = synchronized(this) { routes["$method $path"] } ?: (404 to "")
            val bytes = content.toByteArray(Charsets.UTF_8)
            s.getOutputStream().apply {
                write(
                    ("HTTP/1.1 $status X\r\nContent-Length: ${bytes.size}\r\n" +
                        "Content-Type: application/json; charset=utf-8\r\nConnection: close\r\n\r\n")
                        .toByteArray(),
                )
                write(bytes)
                flush()
            }
        }

        override fun close() = server.close()
    }
}
