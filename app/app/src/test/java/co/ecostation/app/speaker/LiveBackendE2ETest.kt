package co.ecostation.app.speaker

import co.ecostation.app.net.EcoEndpoint
import org.junit.Assert.assertEquals
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

/**
 * Comprobación de extremo a extremo OPCIONAL de la ruta de eventos contra un
 * EcoStation real y en marcha. Se salta salvo que estén las CINCO variables:
 *
 *   ECO_E2E_BASE=http://127.0.0.1:8100   puerto web del backend
 *   ECO_E2E_EMAIL=...                    cuenta de estación/parlante
 *   ECO_E2E_PASSWORD=...
 *   ECO_E2E_READER_KEY=...               Bearer de un lector del aula
 *   ECO_E2E_CARD=...                     credential_uid de una tarjeta emparejada
 *
 * Usa una base de datos desechable: escribe toques. Inicia sesión -> token ->
 * ws -> toque -> cue ACCEPTED, y lo mismo sobre una conexión nueva (reconexión
 * con la sesión reutilizada).
 */
class LiveBackendE2ETest {

    private val base = System.getenv("ECO_E2E_BASE")
    private val email = System.getenv("ECO_E2E_EMAIL")
    private val password = System.getenv("ECO_E2E_PASSWORD")
    private val readerKey = System.getenv("ECO_E2E_READER_KEY")
    private val card = System.getenv("ECO_E2E_CARD")

    @Test(timeout = 60_000)
    fun backendTapReachesThePhoneAsACue() {
        assumeTrue(
            "faltan ECO_E2E_BASE/EMAIL/PASSWORD/READER_KEY/CARD",
            listOf(base, email, password, readerKey, card).all { !it.isNullOrBlank() },
        )
        val uri = URI(base!!)
        val endpoint = EcoEndpoint(uri.host, uri.port)
        val session = FeedSession(email!!, password!!)

        // Ronda 1: toque aceptado.
        assertEquals(FeedbackCue.ACCEPTED, cueFor(session, endpoint) { tap() })

        // Ronda 2: reconexión con la misma sesión (no vuelve a iniciar sesión).
        assertEquals(FeedbackCue.ACCEPTED, cueFor(session, endpoint) { tap() })
    }

    private fun cueFor(session: FeedSession, endpoint: EcoEndpoint, trigger: () -> Unit): FeedbackCue? {
        val ticket = session.ticket(endpoint)
        val ws = WsClient.connect(ticket.wsUrl)
        val cues = LinkedBlockingQueue<FeedbackCue>()
        val reader = thread(isDaemon = true) {
            try {
                ws.readLoop { text -> FeedbackCue.fromFrame(text)?.let(cues::add) }
            } catch (_: Exception) {
            }
        }
        Thread.sleep(500) // pasado el frame hello
        trigger()
        val cue = cues.poll(10, TimeUnit.SECONDS)
        ws.close()
        reader.join(3_000)
        return cue
    }

    private fun tap() {
        val conn = URL("$base/api/v1/events/tap").openConnection() as HttpURLConnection
        conn.requestMethod = "POST"
        conn.doOutput = true
        conn.setRequestProperty("Authorization", "Bearer $readerKey")
        conn.setRequestProperty("Content-Type", "application/json")
        conn.setRequestProperty("Accept", "application/json")
        conn.outputStream.use { it.write("""{"credential_uid":"$card"}""".toByteArray()) }
        val code = conn.responseCode
        println("tap -> HTTP $code")
        conn.disconnect()
    }
}
