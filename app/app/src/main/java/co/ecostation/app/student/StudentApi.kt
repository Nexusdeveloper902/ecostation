package co.ecostation.app.student

import co.ecostation.app.net.EcoEndpoint
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/** The account the token belongs to. `class_name` may be null. */
data class StudentInfo(val id: Int, val name: String, val className: String?)

/** A successful `POST /api/v1/auth/token`. */
data class LoginResult(val token: String, val mustChangePassword: Boolean, val student: StudentInfo)

/** `GET /api/v1/me/balance`; `rank` is null when the student has none. */
data class Balance(val student: StudentInfo, val balance: Int, val rank: Int?)

/**
 * A student-API failure, classified so the screen can react without parsing
 * messages. [message] is always Spanish; for [Kind.VALIDATION] it is the
 * first message the server sent under `errors`.
 */
class ApiException(val kind: Kind, message: String) : IOException(message) {

    enum class Kind {
        /** `422` on login: bad credentials or a non-student account. */
        BAD_CREDENTIALS,

        /** `422` on the password change: the server's Spanish message. */
        VALIDATION,

        /** `403` on `/me/...`: the password must be changed first. */
        PASSWORD_CHANGE_REQUIRED,

        /** `404` on `/me/...`: the account is not linked to a student. */
        NOT_LINKED,

        /** `429`: login/attempt throttle. */
        THROTTLED,

        /** `401` on a request that carried a token. */
        UNAUTHORIZED,

        /** Anything else: bad status, a 403/404 on auth routes or bad JSON. */
        SERVER,
    }
}

/**
 * The Sanctum student API of the web plan, over blocking
 * `HttpURLConnection` with 6 s timeouts. No runtime dependency: `org.json`
 * ships with Android.
 *
 * Every call sends `Accept: application/json`; JSON bodies and responses are
 * UTF-8. A network `IOException` propagates untouched so the UI can show
 * "No se pudo contactar al servidor".
 */
class StudentApi(private val endpoint: EcoEndpoint) {

    fun login(email: String, password: String): LoginResult {
        val payload = JSONObject()
            .put("email", email)
            .put("password", password)
            .put("device_name", DEVICE_NAME)
        val response = request("POST", "/api/v1/auth/token", json = payload)
        return when (response.code) {
            200 -> parse(response) {
                LoginResult(
                    token = it.getString("token"),
                    mustChangePassword = it.optBoolean("must_change_password", false),
                    student = student(it),
                )
            }
            422 -> throw ApiException(
                ApiException.Kind.BAD_CREDENTIALS,
                "Correo o contraseña incorrectos, o la cuenta no es de estudiante",
            )
            429 -> throw throttled()
            else -> throw failed(response.code)
        }
    }

    fun changePassword(token: String, current: String, new: String, confirm: String) {
        val payload = JSONObject()
            .put("current_password", current)
            .put("password", new)
            .put("password_confirmation", confirm)
        val response = request("PUT", "/api/v1/auth/password", token, payload)
        when {
            response.code in 200..299 -> return
            response.code == 401 -> throw unauthorized()
            response.code == 422 -> throw ApiException(
                ApiException.Kind.VALIDATION,
                validationMessage(response.body),
            )
            response.code == 429 -> throw throttled()
            else -> throw failed(response.code)
        }
    }

    /** A `401` means the token was already revoked: that is still success. */
    fun logout(token: String) {
        val response = request("DELETE", "/api/v1/auth/token", token)
        when {
            response.code in 200..299 || response.code == 401 -> return
            response.code == 429 -> throw throttled()
            else -> throw failed(response.code)
        }
    }

    fun balance(token: String): Balance {
        val response = request("GET", "/api/v1/me/balance", token)
        return when (response.code) {
            200 -> parse(response) {
                Balance(
                    student = student(it),
                    balance = it.getInt("balance"),
                    rank = if (it.isNull("rank")) null else it.getInt("rank"),
                )
            }
            401 -> throw unauthorized()
            403 -> throw passwordChangeRequired()
            404 -> throw notLinked()
            429 -> throw throttled()
            else -> throw failed(response.code)
        }
    }

    fun history(token: String, page: Int = 1): HistoryPage {
        val response = request("GET", "/api/v1/me/history?page=$page", token)
        return when (response.code) {
            200 -> parse(response) {
                val data = it.getJSONArray("data")
                val items = (0 until data.length()).map { index -> deposit(data.getJSONObject(index)) }
                HistoryPage(items, hasMore = !it.isNull("next_page_url"))
            }
            401 -> throw unauthorized()
            403 -> throw passwordChangeRequired()
            404 -> throw notLinked()
            429 -> throw throttled()
            else -> throw failed(response.code)
        }
    }

    // --------------------------------------------------------------- parsing

    private fun student(obj: JSONObject): StudentInfo {
        val student = obj.getJSONObject("student")
        return StudentInfo(
            id = student.getInt("id"),
            name = student.getString("name"),
            className = if (student.isNull("class_name")) null else student.getString("class_name"),
        )
    }

    private fun deposit(obj: JSONObject): Deposit = Deposit(
        depositId = obj.getLong("deposit_id"),
        occurredAt = obj.getString("occurred_at"),
        materialClass = if (obj.isNull("material_class")) null else obj.getString("material_class"),
        points = obj.optInt("points_awarded", 0),
        isRecyclable = obj.optBoolean("is_recyclable", false),
    )

    /** Any parse failure on a 2xx body is a server error, never a crash. */
    private fun <T> parse(response: Response, block: (JSONObject) -> T): T = try {
        block(JSONObject(response.body))
    } catch (e: ApiException) {
        throw e
    } catch (e: Exception) {
        throw failed(response.code)
    }

    /** First message under `errors`, which the server sends in Spanish. */
    private fun validationMessage(body: String): String {
        val fallback = "No se pudo cambiar la contraseña"
        return try {
            val errors = JSONObject(body).optJSONObject("errors") ?: return fallback
            val keys = errors.keys()
            while (keys.hasNext()) {
                val message = when (val value = errors.opt(keys.next())) {
                    is JSONArray -> if (value.length() > 0) value.optString(0, "") else ""
                    is String -> value
                    else -> ""
                }
                if (message.isNotEmpty()) return message
            }
            fallback
        } catch (e: Exception) {
            fallback
        }
    }

    // ---------------------------------------------------------------- errors

    private fun unauthorized() = ApiException(
        ApiException.Kind.UNAUTHORIZED,
        "Tu sesión expiró. Inicia sesión de nuevo.",
    )

    private fun passwordChangeRequired() = ApiException(
        ApiException.Kind.PASSWORD_CHANGE_REQUIRED,
        "Debes cambiar tu contraseña para continuar.",
    )

    private fun notLinked() = ApiException(
        ApiException.Kind.NOT_LINKED,
        "Tu cuenta no está vinculada a un estudiante. Avisa a tu docente.",
    )

    private fun throttled() = ApiException(
        ApiException.Kind.THROTTLED,
        "Demasiados intentos, espera un minuto",
    )

    private fun failed(code: Int) = ApiException(
        ApiException.Kind.SERVER,
        "El servidor respondió con un error (HTTP $code)",
    )

    // --------------------------------------------------------------- network

    private data class Response(val code: Int, val body: String)

    private fun request(
        method: String,
        path: String,
        token: String? = null,
        json: JSONObject? = null,
    ): Response {
        val conn = (URL(endpoint.baseUrl + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = TIMEOUT_MS
            readTimeout = TIMEOUT_MS
            useCaches = false
            instanceFollowRedirects = false
            setRequestProperty("Accept", "application/json")
            if (token != null) setRequestProperty("Authorization", "Bearer $token")
            if (json != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }
        try {
            if (json != null) {
                val bytes = json.toString().toByteArray(Charsets.UTF_8)
                conn.setFixedLengthStreamingMode(bytes.size)
                conn.outputStream.use { it.write(bytes) }
            }
            val code = conn.responseCode
            val body = if (code == 204 || code == 205) {
                ""
            } else {
                val stream = if (code in 200..299) conn.inputStream else conn.errorStream
                stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            }
            return Response(code, body)
        } finally {
            conn.disconnect()
        }
    }

    companion object {
        const val TIMEOUT_MS = 6_000
        const val DEVICE_NAME = "EcoStation Android"
    }
}
