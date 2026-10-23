package co.ecostation.app

import android.app.Activity
import android.app.AlertDialog
import android.content.ComponentName
import android.content.pm.PackageManager
import android.nfc.NfcAdapter
import android.nfc.cardemulation.CardEmulation
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.widget.TextView
import co.ecostation.app.hce.ApduProtocol
import co.ecostation.app.hce.CredentialId
import co.ecostation.app.hce.CredentialRuntime
import co.ecostation.app.hce.EcoHostApduService
import co.ecostation.app.hce.HceEvents

/**
 * Plain credential screen: state, the "link this phone" button, diagnostics.
 * The branded card face comes later; the state logic is Pulse's, unchanged.
 */
class MainActivity : Activity() {

    private lateinit var stateView: TextView
    private lateinit var hintView: TextView
    private lateinit var diagView: TextView

    private val handler = Handler(Looper.getMainLooper())

    /**
     * VERIFIED would otherwise flash for a few frames: the reader deselects
     * the instant it has the credential, so the deactivation lands almost on
     * top of the success. Latch it briefly so the person sees it.
     */
    private var verifiedAtMs = 0L
    private val refresh = Runnable { render() }

    private val listener: (String) -> Unit = { event ->
        runOnUiThread {
            if (event.startsWith(AUTH_EVENT)) {
                verifiedAtMs = SystemClock.elapsedRealtime()
                handler.removeCallbacks(refresh)
                handler.postDelayed(refresh, VERIFIED_HOLD_MS)
            }
            render()
        }
    }

    /** Re-renders once a second while an enrollment window counts down. */
    private val tick = object : Runnable {
        override fun run() {
            render()
            if (CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) > 0) {
                handler.postDelayed(this, 1_000L)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        CredentialId.install(this)
        setContentView(R.layout.activity_main)
        stateView = findViewById(R.id.stateView)
        hintView = findViewById(R.id.hintView)
        diagView = findViewById(R.id.diagView)
        findViewById<TextView>(R.id.linkPhone).setOnClickListener { askToLink() }
    }

    override fun onResume() {
        super.onResume()
        HceEvents.addListener(listener)
        render()
        if (CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) > 0) handler.post(tick)
    }

    override fun onPause() {
        HceEvents.removeListener(listener)
        handler.removeCallbacks(refresh)
        handler.removeCallbacks(tick)
        super.onPause()
    }

    /**
     * The holder opens the one-time key hand-off at the pairing desk. A new
     * Keystore key replaces the old one first, so the dialog says so.
     */
    private fun askToLink() {
        AlertDialog.Builder(this)
            .setTitle(R.string.link_title)
            .setMessage(R.string.link_body)
            .setPositiveButton(R.string.link_start) { _, _ ->
                if (!CredentialRuntime.beginEnrollment()) {
                    AlertDialog.Builder(this)
                        .setMessage(R.string.link_failed)
                        .setPositiveButton(android.R.string.ok, null)
                        .show()
                }
                handler.removeCallbacks(tick)
                handler.post(tick)
            }
            .setNegativeButton(R.string.name_cancel, null)
            .show()
    }

    private fun hasKey(): Boolean = CredentialRuntime.keys.hasKey(ApduProtocol.CRED_ID)

    private enum class CardState { READY, LINKED, VERIFIED, NFC_OFF, UNSUPPORTED, LINKING, LINK_SENT, NOT_LINKED }

    private fun currentState(): CardState {
        val hasHce = packageManager.hasSystemFeature(PackageManager.FEATURE_NFC_HOST_CARD_EMULATION)
        val adapter = NfcAdapter.getDefaultAdapter(this)
        if (adapter == null || !hasHce) return CardState.UNSUPPORTED
        if (!adapter.isEnabled) return CardState.NFC_OFF
        if (SystemClock.elapsedRealtime() - verifiedAtMs < VERIFIED_HOLD_MS) return CardState.VERIFIED
        if (CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) > 0) return CardState.LINKING

        // Otherwise reflect the live exchange: LINKED from the reader's
        // SELECT until it deactivates the target.
        val history = HceEvents.history.toList()
        // KEY SENT sticks until the next completed challenge supersedes it.
        val keySent = history.indexOfLast { it.startsWith(ENROLL_EVENT) } >
            history.indexOfLast { it.startsWith(AUTH_EVENT) }
        val live = when (val last = history.lastOrNull()) {
            null -> CardState.READY
            else -> when {
                last.startsWith(AUTH_EVENT) -> CardState.VERIFIED
                last.startsWith("SELECT") -> CardState.LINKED
                keySent -> CardState.LINK_SENT
                else -> CardState.READY
            }
        }
        // A phone without a key cannot answer a reader: say so instead of READY.
        return if (live == CardState.READY && !hasKey()) CardState.NOT_LINKED else live
    }

    private fun render() {
        val state = currentState()
        val seconds = ((CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) + 999) / 1000).toInt()
        stateView.text = when (state) {
            CardState.READY -> getString(R.string.state_ready)
            CardState.LINKED -> getString(R.string.state_linked)
            CardState.VERIFIED -> getString(R.string.state_verified)
            CardState.NFC_OFF -> getString(R.string.state_nfc_off)
            CardState.UNSUPPORTED -> getString(R.string.state_unsupported)
            CardState.LINKING -> getString(R.string.state_linking, seconds / 60, seconds % 60)
            CardState.LINK_SENT -> getString(R.string.state_link_sent)
            CardState.NOT_LINKED -> getString(R.string.state_unlinked)
        }
        hintView.text = when (state) {
            CardState.READY -> getString(R.string.hint_ready)
            CardState.LINKED -> getString(R.string.hint_linked)
            CardState.VERIFIED -> getString(R.string.hint_verified)
            CardState.NFC_OFF -> getString(R.string.hint_nfc_off)
            CardState.UNSUPPORTED -> getString(R.string.hint_unsupported)
            CardState.LINKING -> getString(R.string.hint_linking, seconds)
            CardState.LINK_SENT -> getString(R.string.hint_link_sent)
            CardState.NOT_LINKED -> getString(R.string.hint_unlinked)
        }
        renderDiagnostics()
    }

    private fun renderDiagnostics() {
        val events =
            if (HceEvents.history.isEmpty()) getString(R.string.diag_empty)
            else HceEvents.history.joinToString("\n")
        diagView.text = listOf(
            getString(R.string.diag_credential) to ApduProtocol.CRED_ID,
            getString(R.string.diag_hce) to hceReport(),
            getString(R.string.diag_routing) to routingReport(),
            getString(R.string.diag_key) to getString(if (hasKey()) R.string.diag_key_present else R.string.diag_key_missing),
            getString(R.string.diag_events) to events,
        ).joinToString("\n\n") { (title, body) -> "$title\n$body" }
    }

    private fun hceReport(): String {
        val hasHce = packageManager.hasSystemFeature(PackageManager.FEATURE_NFC_HOST_CARD_EMULATION)
        val adapter = NfcAdapter.getDefaultAdapter(this)
        return when {
            adapter == null || !hasHce -> "no disponible — este teléfono no puede emular tarjetas"
            !adapter.isEnabled -> "disponible — el NFC está apagado"
            else -> "disponible"
        }
    }

    /**
     * Asks the OS whether OUR service is what a reader's SELECT for our AID
     * would reach — the live routing verdict. Each OS query is reported raw on
     * its own line, and OEM API failures never throw out of the render path.
     */
    private fun routingReport(): String {
        val sb = StringBuilder()
        try {
            val adapter = NfcAdapter.getDefaultAdapter(this)
            sb.append("adapter=")
            if (adapter == null) return sb.append("null (this process sees no NFC stack)").toString()
            sb.append("ok(enabled=").append(adapter.isEnabled).append(")\n")

            val ce = CardEmulation.getInstance(adapter)
            val comp = ComponentName(this, EcoHostApduService::class.java)
            var aids: List<String>? = null
            var aidsErr: String? = null
            try {
                aids = ce.getAidsForService(comp, CardEmulation.CATEGORY_OTHER)?.toList()
            } catch (e: Exception) {
                aidsErr = e.javaClass.simpleName
            }
            var def: Boolean? = null
            var defErr: String? = null
            try {
                def = ce.isDefaultServiceForAid(comp, ApduProtocol.AID_HEX)
            } catch (e: Exception) {
                defErr = e.javaClass.simpleName
            }
            sb.append("aids=").append(aids?.joinToString(",") ?: ("null" + (aidsErr?.let { "($it)" } ?: "")))
            sb.append("\ndefault=").append(def?.toString() ?: ("null" + (defErr?.let { "($it)" } ?: "")))
            sb.append("\n").append(ApduProtocol.routingReport(def, aids))
        } catch (e: Exception) {
            sb.append("error: ").append(e.javaClass.simpleName)
        }
        return sb.toString()
    }

    private companion object {
        const val VERIFIED_HOLD_MS = 3_000L
        const val AUTH_EVENT = "CHALLENGE -> 9000"
        const val ENROLL_EVENT = "ENROLL -> 9000"
    }
}
