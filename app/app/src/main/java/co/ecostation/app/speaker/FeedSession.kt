package co.ecostation.app.speaker

import co.ecostation.app.net.BoundedRead
import co.ecostation.app.net.EcoEndpoint
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/**
 * Obtains a realtime feed token from EcoStation the way the station's browser
 * would — no bespoke app API:
 *
 *   GET  /realtime/token  (Accept: JSON, session cookie)  -> {token, url}
 *   401 -> GET /login (session + CSRF _token), POST /login, retry once.
 *
 * The session is reused across reconnects, so the login throttle is only
 * touched when the session really expired.
 */
class FeedSession(private val email: String, private val password: String) {

    /**
     * The credentials were refused, or the login worked but the account
     * cannot open the feed (wrong role, forced password change).
     */
    class LoginRejected(message: String) : IOException(message)

    class Throttled : IOException("demasiados intentos de inicio de sesión (429)")

    data class Ticket(val wsUrl: String)

    /** Cookies per backend `host:port`; a new backend starts a new session. */
    private val jars = HashMap<String, MutableMap<String, String>>()

    @Synchronized
    fun ticket(endpoint: EcoEndpoint): Ticket {
        val jar = jars.getOrPut(endpoint.toString()) { LinkedHashMap() }
        mint(endpoint, jar)?.let { return it }
        login(endpoint, jar)
        return mint(endpoint, jar) ?: throw LoginRejected(
            "la cuenta del parlante no puede abrir el feed: " +
                "revisa su rol o cambia su contraseña en la web",
        )
    }

    /** null = not authenticated (the caller logs in). */
    private fun mint(endpoint: EcoEndpoint, jar: MutableMap<String, String>): Ticket? {
        val conn = open(endpoint, "/realtime/token", jar)
        conn.setRequestProperty("Accept", "application/json")
        val code = conn.responseCode
        absorbCookies(conn, jar)
        return when (code) {
            200 -> {
                val json = JSONObject(BoundedRead.read(conn.inputStream, BoundedRead.JSON_MAX))
                val token = json.getString("token")
                // The backend builds the ws:// URL from the Host we dialled,
                // so it already points at the discovered address.
                val base = json.optString("url").ifEmpty { "ws://${endpoint.host}:8081/app" }
                Ticket("$base?token=" + URLEncoder.encode(token, "UTF-8"))
                    .also { conn.disconnect() }
            }
            401, 302, 419 -> null.also { conn.disconnect() }
            else -> throw IOException("GET /realtime/token respondió HTTP $code").also { conn.disconnect() }
        }
    }

    private fun login(endpoint: EcoEndpoint, jar: MutableMap<String, String>) {
        jar.clear()
        val page = open(endpoint, "/login", jar)
        val pageCode = page.responseCode
        absorbCookies(page, jar)
        if (pageCode == 429) throw Throttled()
        // Every failed exchange on the login routes is an authentication
        // failure, so the bridge applies its 30 s backoff instead of hammering
        // /login (5 attempts/minute). Only socket/connect errors stay fast.
        if (pageCode != 200) throw LoginRejected("GET /login respondió HTTP $pageCode")
        val html = BoundedRead.read(page.inputStream, BoundedRead.SMALL_MAX)
        page.disconnect()
        val csrf = csrfToken(html)
            ?: throw LoginRejected("no hay token CSRF en /login — ¿el servidor es EcoStation?")

        val post = open(endpoint, "/login", jar)
        post.requestMethod = "POST"
        post.doOutput = true
        post.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
        val body = listOf("_token" to csrf, "email" to email, "password" to password)
            .joinToString("&") { (k, v) -> k + "=" + URLEncoder.encode(v, "UTF-8") }
        post.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
        val code = post.responseCode
        absorbCookies(post, jar)
        val location = post.getHeaderField("Location").orEmpty()
        post.disconnect()
        when {
            code == 429 -> throw Throttled()
            // A failed attempt redirects back to the login form.
            code == 302 && location.substringBefore('?').endsWith("/login") ->
                throw LoginRejected("la cuenta del parlante no puede iniciar sesión: revisa su correo y contraseña")
            // 419 (CSRF), 500, a malformed redirect, anything else: auth failure.
            code != 302 -> throw LoginRejected("POST /login respondió HTTP $code — revisa la cuenta del parlante")
        }
    }

    private fun open(endpoint: EcoEndpoint, path: String, jar: Map<String, String>): HttpURLConnection {
        val conn = URL(endpoint.baseUrl + path).openConnection() as HttpURLConnection
        conn.instanceFollowRedirects = false
        conn.useCaches = false
        conn.connectTimeout = TIMEOUT_MS
        conn.readTimeout = TIMEOUT_MS
        if (jar.isNotEmpty()) {
            conn.setRequestProperty("Cookie", jar.entries.joinToString("; ") { "${it.key}=${it.value}" })
        }
        return conn
    }

    private fun absorbCookies(conn: HttpURLConnection, jar: MutableMap<String, String>) {
        conn.headerFields.entries
            .filter { it.key?.equals("Set-Cookie", ignoreCase = true) == true }
            .flatMap { it.value }
            .forEach { header ->
                val pair = header.substringBefore(';')
                val name = pair.substringBefore('=').trim()
                val value = pair.substringAfter('=', "").trim()
                if (name.isEmpty()) return@forEach
                val expired = header.contains("Max-Age=0", ignoreCase = true) ||
                    value.isEmpty() || value == "deleted"
                if (expired) jar.remove(name) else jar[name] = value
            }
    }

    companion object {
        const val TIMEOUT_MS = 4_000

        private val CSRF_INPUT = Regex("""name="_token"[^>]*value="([^"]+)"""")
        private val CSRF_META = Regex("""name="csrf-token"[^>]*content="([^"]+)"""")

        /** Laravel's `@csrf` hidden input (or the csrf-token meta tag). */
        fun csrfToken(html: String): String? =
            (CSRF_INPUT.find(html) ?: CSRF_META.find(html))?.groupValues?.get(1)
    }
}
