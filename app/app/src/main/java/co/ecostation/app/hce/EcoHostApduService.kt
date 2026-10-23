package co.ecostation.app.hce

import android.nfc.cardemulation.HostApduService
import android.os.Bundle
import co.ecostation.app.store.AppSettings

/**
 * Android HCE endpoint for the EcoStation credential.
 * Thin wrapper: [HceResponder] (session + this credential's Keystore key +
 * the enrollment window), event fan-out to the UI.
 * The NFC UID is assigned by the OS per tap and never read here on purpose.
 */
class EcoHostApduService : HostApduService() {

    private val settings by lazy { AppSettings(this) }

    private val responder = HceResponder(
        CredentialRuntime.keys,
        CredentialRuntime.enrollment,
        onAuthenticated = { settings.linkPending = false },
    )

    /**
     * Resolve this device's credential before any reader can ask for it. The
     * NFC stack starts this service on its own — a tap works with the app
     * closed and the phone locked — so the activity cannot be relied on to
     * have run first.
     */
    override fun onCreate() {
        super.onCreate()
        CredentialId.install(this)
    }

    override fun processCommandApdu(commandApdu: ByteArray?, extras: Bundle?): ByteArray {
        if (commandApdu == null) return byteArrayOf(0x6F, 0x00)
        val response = responder.respond(commandApdu)
        HceEvents.emit(responder.describe(commandApdu, response))
        return response
    }

    override fun onDeactivated(reason: Int) {
        responder.deactivate()
        HceEvents.emit(if (reason == DEACTIVATION_LINK_LOSS) "Enlace perdido (teléfono retirado)" else "Desactivado")
    }
}

/** Minimal observable for reader events (service -> activity). No dependencies. */
object HceEvents {
    private val listeners = mutableListOf<(String) -> Unit>()
    private val lock = Any()
    val history = ArrayDeque<String>()

    fun emit(event: String) {
        val snapshot: List<(String) -> Unit>
        synchronized(lock) {
            history.addLast(event)
            while (history.size > 5) history.removeFirst()
            snapshot = listeners.toList()
        }
        snapshot.forEach { it(event) }
    }

    fun addListener(l: (String) -> Unit) = synchronized(lock) { listeners.add(l) }
    fun removeListener(l: (String) -> Unit) = synchronized(lock) { listeners.remove(l) }
}
