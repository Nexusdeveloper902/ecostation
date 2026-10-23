package co.ecostation.app.student

import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/** One deposit row from `GET /api/v1/me/history`. */
data class Deposit(
    val depositId: Long,
    val occurredAt: String,
    val materialClass: String?,
    val points: Int,
    val isRecyclable: Boolean,
)

/** One page of history. `hasMore` mirrors `next_page_url` being present. */
data class HistoryPage(val items: List<Deposit>, val hasMore: Boolean)

/** The materials the ledger knows, in Spanish. Unknown and null -> "Otro". */
fun materialLabel(materialClass: String?): String = when (materialClass) {
    "plastic" -> "Plástico"
    "metal" -> "Metal"
    "glass" -> "Vidrio"
    "paper" -> "Papel"
    else -> "Otro"
}

/**
 * `occurred_at` (ISO-8601 with an offset) -> `dd/MM HH:mm` in Bogotá. Input
 * that does not parse is returned unchanged, never thrown: the history must
 * survive a server that sends a different format.
 */
fun displayTime(occurredAt: String): String {
    val bogota = TimeZone.getTimeZone("America/Bogota")
    return try {
        val parser = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.ROOT).apply {
            timeZone = bogota
            isLenient = false
        }
        val output = SimpleDateFormat("dd/MM HH:mm", Locale.ROOT).apply { timeZone = bogota }
        parser.parse(occurredAt)?.let { output.format(it) } ?: occurredAt
    } catch (e: Exception) {
        occurredAt
    }
}
