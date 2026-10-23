package co.ecostation.app.speaker

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.Network
import android.os.Build
import android.util.Log
import co.ecostation.app.net.BackendDiscovery
import co.ecostation.app.net.EcoEndpoint
import co.ecostation.app.store.AppSettings
import java.io.IOException
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Ruta de eventos del backend: descubrir -> iniciar sesión -> abrir el feed en
 * tiempo real -> sonar por cada toque, sobreviviendo a caídas. Un solo hilo
 * trabajador, arrancado y parado con la actividad (la app queda abierta durante
 * la demo, así que no hay servicio en primer plano).
 *
 * Política de recuperación (igual que el firmware: "reconsultar tras cualquier
 * fallo de transporte"): cada conexión fallida o caída olvida el endpoint y
 * vuelve a descubrir, con retroceso 1 → 2 → 4 → 8 s (tope 10 s; 30 s tras un
 * rechazo de inicio de sesión o un 429, para no tocar el límite de 5/min). Un
 * cambio de red — Wi-Fi/datos que sube o baja, hotspot — corta la espera.
 *
 * La cuenta y la dirección se leen de [AppSettings] en cada intento, así que
 * un cambio en Ajustes se aplica al volver a esta pantalla. La sesión HTTP se
 * reutiliza mientras las credenciales no cambien: reconectar no vuelve a
 * iniciar sesión (y no consume el límite de intentos).
 */
class FeedbackBridge(
    context: Context,
    private val settings: AppSettings,
    private val onCue: (FeedbackCue) -> Unit,
    private val onStatus: (String) -> Unit,
) {
    private val app = context.applicationContext
    private val discovery = BackendDiscovery(app)
    private val connectivity = app.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
    private val wake = Object()

    /** Una bandera por trabajador, para que un stop()/start() rápido no deje dos vivos. */
    private var alive: AtomicBoolean? = null
    @Volatile private var nudged = false
    @Volatile private var client: WsClient? = null
    private var worker: Thread? = null

    private var session: FeedSession? = null
    private var sessionEmail: String? = null
    private var sessionPassword: String? = null

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) = nudge()
        override fun onLost(network: Network) = nudge()
    }

    /** Encender/apagar el hotspot no aparece como una Network: se escucha directo. */
    private val hotspotReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) = nudge()
    }

    fun start() {
        if (alive != null) return
        val flag = AtomicBoolean(true)
        alive = flag
        try {
            connectivity.registerDefaultNetworkCallback(networkCallback)
        } catch (_: Exception) {
        }
        val filter = IntentFilter(ACTION_WIFI_AP_STATE_CHANGED)
        if (Build.VERSION.SDK_INT >= 33) {
            app.registerReceiver(hotspotReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            app.registerReceiver(hotspotReceiver, filter)
        }
        worker = Thread({ run(flag) }, "eco-speaker").apply { isDaemon = true; start() }
    }

    fun stop() {
        val flag = alive ?: return
        flag.set(false)
        alive = null
        try {
            connectivity.unregisterNetworkCallback(networkCallback)
        } catch (_: Exception) {
        }
        try {
            app.unregisterReceiver(hotspotReceiver)
        } catch (_: Exception) {
        }
        client?.close()
        worker?.interrupt()
        synchronized(wake) { wake.notifyAll() }
        worker = null
    }

    /** Corta la espera de retroceso actual (una conexión viva no se toca). */
    private fun nudge() {
        nudged = true
        synchronized(wake) { wake.notifyAll() }
    }

    private fun run(running: AtomicBoolean) {
        var failures = 0
        while (running.get()) {
            try {
                val email = settings.speakerEmail?.trim().orEmpty()
                val password = settings.speakerPassword.orEmpty()
                if (email.isEmpty() || password.isEmpty()) {
                    status(MISSING_ACCOUNT)
                    sleep(AUTH_RETRY_MS, running)
                    continue
                }

                status("buscando servidor…")
                val endpoint = EcoEndpoint.locate(settings.serverAddress) { discovery.discover(::log) }
                    ?: throw IOException("no se encontró el servidor en esta red")

                status("servidor $endpoint: iniciando sesión…")
                val ticket = sessionFor(email, password).ticket(endpoint)
                // The first backend that hands over a feed token becomes the
                // pinned address: a later mDNS/probe answer must not redirect
                // the station password somewhere else.
                if (settings.serverAddress.isNullOrBlank()) settings.serverAddress = endpoint.toString()
                val ws = WsClient.connect(ticket.wsUrl)
                client = ws
                if (!running.get()) {
                    ws.close()
                    break
                }
                failures = 0
                status("EN VIVO · $endpoint")
                ws.readLoop(isActive = running::get) { text -> FeedbackCue.fromFrame(text)?.let(onCue) }
                ws.close()
                if (!running.get()) break
                throw IOException("el servidor cerró el feed")
            } catch (e: InterruptedException) {
                break
            } catch (e: Exception) {
                if (!running.get()) break
                failures++
                // Cualquier fallo de transporte: olvidar la dirección y
                // redescubrir — el backend pudo cambiar de IP o de red.
                val delayMs = when (e) {
                    is FeedSession.LoginRejected, is FeedSession.Throttled -> AUTH_RETRY_MS
                    else -> minOf(MAX_BACKOFF_MS, 1000L shl minOf(failures - 1, 4))
                }
                status("reintento en ${delayMs / 1000}s: ${e.message ?: e.javaClass.simpleName}")
                Log.w(TAG, "fallo del feed #$failures", e)
                sleep(delayMs, running)
            } finally {
                client = null
            }
        }
    }

    /** Una sesión por credenciales: reconectar reutiliza cookies y no reloguea. */
    private fun sessionFor(email: String, password: String): FeedSession {
        session?.let { if (sessionEmail == email && sessionPassword == password) return it }
        return FeedSession(email, password).also {
            session = it
            sessionEmail = email
            sessionPassword = password
        }
    }

    private fun sleep(ms: Long, running: AtomicBoolean) {
        nudged = false
        val until = System.currentTimeMillis() + ms
        synchronized(wake) {
            while (running.get() && !nudged) {
                val left = until - System.currentTimeMillis()
                if (left <= 0) break
                try {
                    wake.wait(left)
                } catch (e: InterruptedException) {
                    return
                }
            }
        }
    }

    private fun status(text: String) {
        Log.i(TAG, text)
        onStatus(text)
    }

    private fun log(text: String) = Log.i(TAG, text)

    private companion object {
        const val TAG = "EcoSpeaker"
        const val MISSING_ACCOUNT = "falta la cuenta del parlante en Ajustes"
        const val MAX_BACKOFF_MS = 10_000L
        const val AUTH_RETRY_MS = 30_000L

        /** WifiManager.WIFI_AP_STATE_CHANGED_ACTION (constante de sistema). */
        const val ACTION_WIFI_AP_STATE_CHANGED = "android.net.wifi.WIFI_AP_STATE_CHANGED"
    }
}
