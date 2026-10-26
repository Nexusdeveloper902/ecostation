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

    /**
     * Built in [onCreate], never lazily on the APDU thread: the NFC stack may
     * start this service on its own and the first tap must not wait for a
     * SharedPreferences/Keystore touch.
     */
    private lateinit var settings: AppSettings
    private lateinit var responder: HceResponder

    override fun onCreate() {
        super.onCreate()
        settings = AppSettings(this)
        CredentialId.install(this)

        // Warm both lazy paths here, where the NFC stack already allows a
        // slower start: the first APDU must not block on the prefs latch or on
        // KeyStore.load() (the RF field timeout is short).
        settings.linkPending
        CredentialRuntime.keys.hasKey(ApduProtocol.CRED_ID)

        responder = HceResponder(
            CredentialRuntime.keys,
            CredentialRuntime.enrollment,
            onAuthenticated = {
                // Only a CHALLENGE inside the short desk window proves the
                // backend adopted the key. A later one may be answered with the
                // OLD key while a re-link window is open, so keep SENT then.
                settings.updateLinkFlags {
                    LinkState.afterChallenge(
                        flags = it,
                        windowRemainingMs = CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()),
                        nowMs = System.currentTimeMillis(),
                    )
                }
            },
            onAdopting = {
                // Before keys.adopt: the fail-safe worst case is a visible
                // LLAVE ENVIADA, never a silent success. One edit also closes
                // the window.
                settings.updateLinkFlags {
                    LinkState.adoptionStarted(it, System.currentTimeMillis())
                }
            },
            onAdoptFailed = {
                // The store refused the import: roll the optimistic flag back.
                settings.updateLinkFlags(LinkState::adoptionFailed)
                if (settings.linkFirst) {
                    // A first link imported a provisional key. The reader did
                    // not receive it, so remove it and return to SIN VINCULAR.
                    CredentialRuntime.keys.delete(ApduProtocol.CRED_ID)
                    settings.linkFirst = false
                }
            },
            onAdopted = {
                // ENROLL delivered the key: it is no longer provisional.
                settings.linkFirst = false
            },
        )
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
    private val history = ArrayDeque<String>()

    fun emit(event: String) {
        val snapshot = remember(event)
        snapshot.forEach { it(event) }
    }

    /**
     * Appends and returns a listener snapshot. `@Synchronized` (same monitor
     * as [historySnapshot]) so the UI can read a coherent last-5 at any time.
     */
    @Synchronized
    private fun remember(event: String): List<(String) -> Unit> {
        history.addLast(event)
        while (history.size > 5) history.removeFirst()
        return listeners.toList()
    }

    /** Immutable copy of the last events, safe to read from any thread. */
    @Synchronized
    fun historySnapshot(): List<String> = history.toList()

    @Synchronized
    fun addListener(l: (String) -> Unit) {
        listeners.add(l)
    }

    @Synchronized
    fun removeListener(l: (String) -> Unit) {
        listeners.remove(l)
    }
}
