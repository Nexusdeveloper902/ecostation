package co.ecostation.app

import android.app.Activity
import android.app.AlertDialog
import android.content.ComponentName
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.PorterDuff
import android.media.AudioManager
import android.nfc.NfcAdapter
import android.nfc.cardemulation.CardEmulation
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.Settings as AndroidSettings
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.Switch
import android.widget.TextView
import co.ecostation.app.hce.ApduProtocol
import co.ecostation.app.hce.CredentialId
import co.ecostation.app.hce.CredentialRuntime
import co.ecostation.app.hce.EcoHostApduService
import co.ecostation.app.hce.HceEvents
import co.ecostation.app.speaker.FeedbackBridge
import co.ecostation.app.speaker.FeedbackCue
import co.ecostation.app.speaker.FeedbackSounds
import co.ecostation.app.store.AppSettings
import co.ecostation.app.ui.BottomNav
import co.ecostation.app.ui.EcoTab
import co.ecostation.app.ui.TapTargetView
import java.text.SimpleDateFormat
import java.util.ArrayDeque
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Pantalla de la credencial y del modo parlante.
 *
 * Las dos primeras pestañas de la barra inferior son secciones de esta misma
 * actividad: `cardSection` y `speakerSection`. La lógica de estados, el
 * enrolamiento y el puente del parlante son los de las tareas 2 y 6; aquí solo
 * se les da la piel aprobada en Penpot.
 */
class MainActivity : Activity() {

    private lateinit var cardSection: View
    private lateinit var speakerSection: View
    private lateinit var credentialCard: View
    private lateinit var cardHolderName: TextView
    private lateinit var cardLogo: ImageView
    private lateinit var cardContactless: ImageView
    private lateinit var cardHolderClass: TextView
    private lateinit var cardCredentialId: TextView
    private lateinit var stateChip: TextView
    private lateinit var tapTarget: TapTargetView
    private lateinit var unlinkedGroup: View
    private lateinit var linkCancel: Button
    private lateinit var nfcSettingsButton: Button
    private lateinit var lastTapPill: TextView

    private lateinit var stateView: TextView
    private lateinit var hintView: TextView
    private lateinit var diagView: TextView
    private lateinit var feedView: TextView
    private lateinit var audioView: TextView
    private lateinit var diagBody: View
    private lateinit var diagChevron: ImageView

    private lateinit var speakerSwitch: Switch
    private lateinit var speakerServerValue: TextView
    private lateinit var speakerServerChip: TextView
    private lateinit var speakerBluetoothValue: TextView
    private lateinit var speakerBluetoothChip: TextView
    private lateinit var speakerAccountValue: TextView
    private lateinit var speakerAccountChip: TextView
    private lateinit var speakerAddressValue: TextView
    private lateinit var signalList: LinearLayout
    private lateinit var signalEmpty: TextView

    private lateinit var settings: AppSettings

    /** Sonido local (HCE) y del backend en tiempo real. */
    private lateinit var sounds: FeedbackSounds
    private lateinit var bridge: FeedbackBridge
    @Volatile private var feedStatus = ""
    private var lastCue = "—"
    private var speakerRunning = false

    private var section = SECTION_CARD
    private var diagnosticsOpen = false
    private var lastTapAtMs = 0L

    /**
     * Hot-path caches. A reader APDU must never wait on the Keystore, the NFC
     * stack or the audio service: those answers are refreshed on resume and on
     * an explicit diagnostics refresh, and only read here.
     */
    private var keyPresent: Boolean? = null
    private var routingText: String? = null
    private var audioText: String? = null

    private data class Signal(val cue: FeedbackCue, val atMs: Long)

    private val signals = ArrayDeque<Signal>()

    private val handler = Handler(Looper.getMainLooper())

    /** Coalesced render: an APDU burst queues at most one UI pass. */
    private var renderQueued = false
    private val renderRunnable = Runnable {
        renderQueued = false
        render()
    }

    /**
     * VERIFIED would otherwise flash for a few frames: the reader deselects
     * the instant it has the credential, so the deactivation lands almost on
     * top of the success. Latch it briefly so the person sees it.
     */
    private var verifiedAtMs = 0L
    private val refresh = Runnable { render() }

    /**
     * Called by the HCE service on its APDU thread. The sound is played here
     * (SoundPool is cheap and thread-safe); everything that touches views or
     * the Keystore is posted and coalesced, so the APDU response never waits
     * for a render.
     */
    private val listener: (String) -> Unit = { event ->
        val cue = FeedbackCue.fromHceEvent(event)
        if (cue != null) sounds.play(cue)
        handler.post {
            if (isFinishing || isDestroyed) return@post
            lastTapAtMs = System.currentTimeMillis()
            if (cue != null) recordCue(cue)
            if (event.startsWith(AUTH_EVENT)) {
                verifiedAtMs = SystemClock.elapsedRealtime()
                keyPresent = true
                if (settings.linkPending) settings.linkPending = false
                handler.removeCallbacks(refresh)
                handler.postDelayed(refresh, VERIFIED_HOLD_MS)
            } else if (event.startsWith(ENROLL_EVENT)) {
                keyPresent = true
            } else if (event.startsWith(ENROLL_ERROR_EVENT)) {
                // The reader got 6985: nothing was adopted, so do not keep
                // promising "llave enviada".
                settings.linkPending = false
                refreshKeyPresence()
            }
            postRender()
        }
    }

    /** Re-renders once a second while an enrollment window counts down. */
    private val tick = object : Runnable {
        override fun run() {
            postRender()
            if (CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) > 0) {
                handler.postDelayed(this, 1_000L)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        CredentialId.install(this)
        section = intent?.getStringExtra(EXTRA_SECTION) ?: SECTION_CARD
        setContentView(R.layout.activity_main)

        cardSection = findViewById(R.id.cardSection)
        speakerSection = findViewById(R.id.speakerSection)
        credentialCard = findViewById(R.id.credentialCard)
        cardHolderName = findViewById(R.id.cardHolderName)
        cardLogo = findViewById(R.id.cardLogo)
        cardContactless = findViewById(R.id.cardContactless)
        cardHolderClass = findViewById(R.id.cardHolderClass)
        cardCredentialId = findViewById(R.id.cardCredentialId)
        stateChip = findViewById(R.id.stateChip)
        tapTarget = findViewById(R.id.tapTarget)
        unlinkedGroup = findViewById(R.id.unlinkedGroup)
        linkCancel = findViewById(R.id.linkCancel)
        nfcSettingsButton = findViewById(R.id.nfcSettingsButton)
        lastTapPill = findViewById(R.id.lastTapPill)

        stateView = findViewById(R.id.stateView)
        hintView = findViewById(R.id.hintView)
        diagView = findViewById(R.id.diagView)
        feedView = findViewById(R.id.feedView)
        audioView = findViewById(R.id.audioView)
        diagBody = findViewById(R.id.diagBody)
        diagChevron = findViewById(R.id.diagChevron)

        speakerSwitch = findViewById(R.id.speakerSwitch)
        speakerServerValue = findViewById(R.id.speakerServerValue)
        speakerServerChip = findViewById(R.id.speakerServerChip)
        speakerBluetoothValue = findViewById(R.id.speakerBluetoothValue)
        speakerBluetoothChip = findViewById(R.id.speakerBluetoothChip)
        speakerAccountValue = findViewById(R.id.speakerAccountValue)
        speakerAccountChip = findViewById(R.id.speakerAccountChip)
        speakerAddressValue = findViewById(R.id.speakerAddressValue)
        signalList = findViewById(R.id.signalList)
        signalEmpty = findViewById(R.id.signalEmpty)

        findViewById<TextView>(R.id.linkPhone).setOnClickListener { askToLink() }
        findViewById<TextView>(R.id.studentButton).setOnClickListener {
            startActivity(Intent(this, StudentActivity::class.java))
            finish()
        }
        findViewById<TextView>(R.id.settingsButton).setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
            finish()
        }
        linkCancel.setOnClickListener {
            CredentialRuntime.enrollment.close()
            settings.linkPending = false
            handler.removeCallbacks(tick)
            refreshKeyPresence()
            postRender()
        }
        nfcSettingsButton.setOnClickListener { openNfcSettings() }
        findViewById<View>(R.id.speakerAddressRow).setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
            finish()
        }
        findViewById<View>(R.id.diagToggle).setOnClickListener { toggleDiagnostics() }
        // Prueba de banco: tocar la línea de audio reproduce los cinco sonidos.
        audioView.setOnClickListener { playTestSequence() }

        settings = AppSettings(this)
        sounds = FeedbackSounds(this)
        bridge = FeedbackBridge(
            this,
            settings,
            onCue = { cue ->
                // Worker thread: the SoundPool call is safe here; the UI work
                // is posted like the HCE path.
                sounds.play(cue)
                handler.post {
                    if (isFinishing || isDestroyed) return@post
                    recordCue(cue)
                    postRender()
                }
            },
            onStatus = { text ->
                feedStatus = text
                handler.post {
                    if (isFinishing || isDestroyed) return@post
                    renderDiagnostics()
                    updateSpeakerUi()
                }
            },
        )
        speakerSwitch.isChecked = settings.speakerEnabled
        speakerSwitch.setOnCheckedChangeListener { _, checked ->
            if (settings.speakerEnabled == checked) return@setOnCheckedChangeListener
            settings.speakerEnabled = checked
            if (checked) startSpeaker() else stopSpeaker()
            updateSpeakerUi()
        }

        BottomNav.bind(this, activeTab()) { tab -> onTab(tab) }
        showSection(section)
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        if (intent == null) return
        setIntent(intent)
        section = intent.getStringExtra(EXTRA_SECTION) ?: section
        showSection(section)
    }

    /**
     * El modo parlante solo vive mientras esta pantalla está visible: mantiene
     * la pantalla encendida y el volumen físico sobre el flujo multimedia, y
     * arranca el puente. Sin modo parlante no hay tráfico de red ni keep-alive.
     */
    override fun onStart() {
        super.onStart()
        if (settings.speakerEnabled) startSpeaker() else stopSpeaker()
        updateSpeakerUi()
    }

    override fun onStop() {
        stopSpeaker()
        super.onStop()
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        renderQueued = false
        sounds.release()
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        HceEvents.addListener(listener)
        refreshCaches()
        render()
        updateSpeakerUi()
        if (CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) > 0) handler.post(tick)
    }

    override fun onPause() {
        HceEvents.removeListener(listener)
        handler.removeCallbacks(refresh)
        handler.removeCallbacks(renderRunnable)
        handler.removeCallbacks(tick)
        handler.removeCallbacksAndMessages(TEST_SEQUENCE_TOKEN)
        renderQueued = false
        super.onPause()
    }

    /** Keystore/NFC/audio binders: only on resume and on explicit refresh. */
    private fun refreshCaches() {
        refreshKeyPresence()
        routingText = routingReport()
        audioText = sounds.outputDescription()
    }

    private fun refreshKeyPresence() {
        keyPresent = CredentialRuntime.keys.hasKey(ApduProtocol.CRED_ID)
    }

    private fun postRender() {
        if (renderQueued) return
        renderQueued = true
        handler.post(renderRunnable)
    }

    // -------------------------------------------------------------- pestañas

    private fun activeTab(): EcoTab =
        if (section == SECTION_SPEAKER) EcoTab.SPEAKER else EcoTab.CARD

    private fun onTab(tab: EcoTab) {
        when (tab) {
            EcoTab.CARD -> showSection(SECTION_CARD)
            EcoTab.SPEAKER -> showSection(SECTION_SPEAKER)
            EcoTab.POINTS -> {
                startActivity(Intent(this, StudentActivity::class.java))
                finish()
            }
            EcoTab.SETTINGS -> {
                startActivity(Intent(this, SettingsActivity::class.java))
                finish()
            }
        }
    }

    private fun showSection(value: String) {
        section = value
        cardSection.visibility = if (section == SECTION_CARD) View.VISIBLE else View.GONE
        speakerSection.visibility = if (section == SECTION_SPEAKER) View.VISIBLE else View.GONE
        findViewById<TextView>(R.id.topBarTitle).setText(
            if (section == SECTION_SPEAKER) R.string.speaker_title else R.string.nav_card,
        )
        BottomNav.bind(this, activeTab()) { tab -> onTab(tab) }
        render()
        updateSpeakerUi()
    }

    // ------------------------------------------------------------- vinculación

    /**
     * The holder opens the one-time key hand-off at the pairing desk. The
     * current key keeps working until the reader confirms ENROLL, so a failed
     * or cancelled link never leaves a dead credential.
     */
    private fun askToLink() {
        AlertDialog.Builder(this)
            .setTitle(R.string.link_title)
            .setMessage(R.string.link_body)
            .setPositiveButton(R.string.link_start) { _, _ ->
                if (!CredentialRuntime.beginEnrollment()) {
                    settings.linkPending = false
                    AlertDialog.Builder(this)
                        .setMessage(R.string.link_failed)
                        .setPositiveButton(android.R.string.ok, null)
                        .show()
                } else {
                    settings.linkPending = true
                }
                handler.removeCallbacks(tick)
                handler.post(tick)
                refreshKeyPresence()
                postRender()
            }
            .setNegativeButton(R.string.name_cancel, null)
            .show()
    }

    private fun openNfcSettings() {
        try {
            startActivity(Intent(AndroidSettings.ACTION_NFC_SETTINGS))
        } catch (_: Exception) {
            try {
                startActivity(Intent(AndroidSettings.ACTION_WIRELESS_SETTINGS))
            } catch (_: Exception) {
                // El teléfono no expone ninguna de las dos: la pista de texto queda.
            }
        }
    }

    /** Cached Keystore answer; refreshed on resume, cancel and ENROLL/CHALLENGE. */
    private fun hasKey(): Boolean = keyPresent ?: false

    private enum class CardState { READY, LINKED, VERIFIED, NFC_OFF, UNSUPPORTED, LINKING, LINK_SENT, NOT_LINKED }

    private fun currentState(): CardState {
        val hasHce = packageManager.hasSystemFeature(PackageManager.FEATURE_NFC_HOST_CARD_EMULATION)
        val adapter = NfcAdapter.getDefaultAdapter(this)
        if (adapter == null || !hasHce) return CardState.UNSUPPORTED
        if (!adapter.isEnabled) return CardState.NFC_OFF
        if (SystemClock.elapsedRealtime() - verifiedAtMs < VERIFIED_HOLD_MS) return CardState.VERIFIED
        if (CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs()) > 0) return CardState.LINKING

        // LLAVE ENVIADA until a CHALLENGE proves the reader knows the new key.
        if (settings.linkPending) return CardState.LINK_SENT

        // A phone without a key cannot answer a reader: say so instead of READY.
        if (!hasKey()) return CardState.NOT_LINKED

        // Otherwise reflect the live exchange: LINKED from the reader's
        // SELECT until it deactivates the target.
        val last = HceEvents.history.toList().lastOrNull()
        return when {
            last == null -> CardState.READY
            last.startsWith(AUTH_EVENT) -> CardState.VERIFIED
            last.startsWith(SELECT_EVENT) -> CardState.LINKED
            else -> CardState.READY
        }
    }

    private fun render() {
        val state = currentState()
        val remaining = CredentialRuntime.enrollment.remainingMs(CredentialRuntime.nowMs())
        val seconds = ((remaining + 999) / 1000).toInt()
        renderCard(state, seconds, remaining)
        renderDiagnostics()
    }

    private fun renderCard(state: CardState, seconds: Int, remainingMs: Long) {
        cardCredentialId.text = ApduProtocol.CRED_ID
        val danger = state == CardState.NFC_OFF || state == CardState.UNSUPPORTED
        credentialCard.setBackgroundResource(if (danger) R.drawable.bg_card_danger else R.drawable.bg_card_green)
        cardLogo.setImageResource(if (danger) R.drawable.logo_horizontal else R.drawable.logo_horizontal_light)
        cardContactless.setColorFilter(
            getColor(if (danger) R.color.eco_ink else R.color.eco_white),
            PorterDuff.Mode.SRC_IN,
        )
        cardHolderName.setTextColor(getColor(if (danger) R.color.eco_ink else R.color.eco_white))
        cardHolderClass.setTextColor(getColor(if (danger) R.color.eco_slate else R.color.card_text_soft))
        cardCredentialId.setTextColor(getColor(if (danger) R.color.eco_slate else R.color.card_id_soft))
        cardHolderClass.visibility = View.GONE

        when (state) {
            CardState.READY -> {
                setChip(R.string.chip_ready, R.drawable.bg_pill_white, R.color.eco_ink, R.drawable.ic_dot_green)
                stateView.setText(R.string.state_ready_title)
                hintView.setText(R.string.hint_ready)
                stateView.setTextColor(getColor(R.color.eco_ink))
                hintView.setTextColor(getColor(R.color.eco_slate))
            }
            CardState.LINKED -> {
                setChip(R.string.chip_linked, R.drawable.bg_pill_white, R.color.eco_ink, R.drawable.ic_dot_green)
                stateView.setText(R.string.state_linked)
                hintView.setText(R.string.hint_linked)
                stateView.setTextColor(getColor(R.color.eco_ink))
                hintView.setTextColor(getColor(R.color.eco_slate))
            }
            CardState.VERIFIED -> {
                setChip(R.string.chip_verified, R.drawable.bg_pill_white, R.color.eco_ink, R.drawable.ic_dot_green)
                stateView.setText(R.string.state_verified)
                hintView.setText(R.string.hint_verified)
                stateView.setTextColor(getColor(R.color.eco_ink))
                hintView.setTextColor(getColor(R.color.eco_slate))
            }
            CardState.LINKING -> {
                setChip(R.string.chip_linking, R.drawable.bg_pill_white, R.color.eco_warning_text, R.drawable.ic_dot_amber)
                stateView.text = getString(R.string.state_linking_title)
                hintView.text = getString(R.string.hint_linking, seconds)
                stateView.setTextColor(getColor(R.color.eco_ink))
                hintView.setTextColor(getColor(R.color.eco_slate))
            }
            CardState.LINK_SENT -> {
                setChip(R.string.chip_link_sent, R.drawable.bg_pill_white, R.color.eco_ink, R.drawable.ic_dot_green)
                stateView.setText(R.string.state_link_sent)
                hintView.setText(R.string.hint_link_sent)
                stateView.setTextColor(getColor(R.color.eco_ink))
                hintView.setTextColor(getColor(R.color.eco_slate))
            }
            CardState.NOT_LINKED -> {
                setChip(R.string.chip_unlinked, R.drawable.bg_pill_white, R.color.eco_ink, R.drawable.ic_dot_red)
                stateView.setText(R.string.unlinked_title)
                hintView.visibility = View.GONE
                stateView.setTextColor(getColor(R.color.eco_ink))
            }
            CardState.NFC_OFF -> {
                setChip(R.string.chip_nfc_off, R.drawable.bg_pill_danger, R.color.eco_danger_text, R.drawable.ic_dot_red)
                stateView.setText(R.string.state_nfc_off)
                hintView.setText(R.string.hint_nfc_off)
                stateView.setTextColor(getColor(R.color.eco_danger_text))
                hintView.setTextColor(getColor(R.color.eco_danger_text))
            }
            CardState.UNSUPPORTED -> {
                setChip(R.string.chip_unsupported, R.drawable.bg_pill_danger, R.color.eco_danger_text, R.drawable.ic_dot_red)
                stateView.setText(R.string.state_unsupported)
                hintView.setText(R.string.hint_unsupported)
                stateView.setTextColor(getColor(R.color.eco_danger_text))
                hintView.setTextColor(getColor(R.color.eco_danger_text))
            }
        }
        hintView.visibility = if (state == CardState.NOT_LINKED) View.GONE else View.VISIBLE

        unlinkedGroup.visibility = if (state == CardState.NOT_LINKED) View.VISIBLE else View.GONE
        findViewById<View>(R.id.linkPhone).visibility =
            if (state == CardState.NOT_LINKED) View.VISIBLE else View.GONE
        linkCancel.visibility =
            if (state == CardState.LINKING || state == CardState.LINK_SENT) View.VISIBLE else View.GONE
        nfcSettingsButton.visibility = if (state == CardState.NFC_OFF) View.VISIBLE else View.GONE

        val showTarget = state == CardState.READY || state == CardState.LINKED ||
            state == CardState.VERIFIED || state == CardState.LINK_SENT || state == CardState.LINKING
        tapTarget.visibility = if (showTarget) View.VISIBLE else View.GONE
        (stateView.layoutParams as? LinearLayout.LayoutParams)?.let {
            it.topMargin = dp(if (showTarget) 8 else 24)
            stateView.layoutParams = it
        }
        if (state == CardState.LINKING) {
            tapTarget.showCountdown(seconds, remainingMs.toFloat() / ENROLLMENT_TTL_MS)
        } else {
            tapTarget.showPhone()
        }
        tapTarget.contentDescription = if (state == CardState.LINKING) {
            getString(R.string.state_linking, seconds / 60, seconds % 60)
        } else {
            stateView.text
        }

        renderLastTap()
    }

    private fun renderLastTap() {
        if (lastTapAtMs <= 0L) {
            lastTapPill.visibility = View.GONE
            return
        }
        val zone = TimeZone.getTimeZone("America/Bogota")
        val now = Date()
        val then = Date(lastTapAtMs)
        val day = SimpleDateFormat("yyyyMMdd", Locale.US).apply { timeZone = zone }
        val time = SimpleDateFormat("HH:mm", Locale("es", "CO")).apply { timeZone = zone }
        lastTapPill.text = if (day.format(now) == day.format(then)) {
            getString(R.string.last_tap_today, time.format(then))
        } else {
            val full = SimpleDateFormat("dd/MM HH:mm", Locale("es", "CO")).apply { timeZone = zone }
            getString(R.string.last_tap_date, full.format(then))
        }
        lastTapPill.visibility = View.VISIBLE
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    private fun setChip(text: Int, background: Int, color: Int, dot: Int) {
        stateChip.setText(text)
        stateChip.setBackgroundResource(background)
        stateChip.setTextColor(getColor(color))
        stateChip.setCompoundDrawablesWithIntrinsicBounds(dot, 0, 0, 0)
    }

    // ------------------------------------------------------------- diagnóstico

    private fun toggleDiagnostics() {
        diagnosticsOpen = !diagnosticsOpen
        diagBody.visibility = if (diagnosticsOpen) View.VISIBLE else View.GONE
        diagChevron.rotation = if (diagnosticsOpen) 90f else 0f
        if (diagnosticsOpen) {
            // Explicit refresh: these are the only binder reads in diagnostics.
            refreshCaches()
            renderDiagnostics()
        }
    }

    private fun renderDiagnostics() {
        val events =
            if (HceEvents.history.isEmpty()) getString(R.string.diag_empty)
            else HceEvents.history.joinToString("\n")
        diagView.text = listOf(
            getString(R.string.diag_credential) to ApduProtocol.CRED_ID,
            getString(R.string.diag_hce) to hceReport(),
            getString(R.string.diag_routing) to (routingText ?: "—"),
            getString(R.string.diag_key) to getString(if (hasKey()) R.string.diag_key_present else R.string.diag_key_missing),
            getString(R.string.diag_events) to events,
        ).joinToString("\n\n") { (title, body) -> "$title\n$body" }
        feedView.text = getString(R.string.diag_feed) + "\n" +
            getString(R.string.diag_feed_body, feedStatus.ifEmpty { "—" }, lastCue)
        audioView.text = getString(R.string.diag_audio) + "\n" +
            getString(R.string.diag_audio_body, audioText ?: "—")

        val address = settings.serverAddress?.takeIf { it.isNotBlank() }
        speakerAddressValue.text = address ?: getString(R.string.speaker_server_auto)
    }

    // ---------------------------------------------------------------- sonido

    /** UI bookkeeping for a cue; the sound is already playing. */
    private fun recordCue(cue: FeedbackCue) {
        val time = SimpleDateFormat("HH:mm:ss", Locale.US).format(Date())
        lastCue = "${cue.name.lowercase(Locale.ROOT)} @ $time"
        signals.addFirst(Signal(cue, System.currentTimeMillis()))
        while (signals.size > SIGNAL_LIMIT) signals.removeLast()
        renderSignals()
    }

    /** Bridge/test path: play, record and repaint in one main-thread step. */
    private fun cue(cue: FeedbackCue) {
        sounds.play(cue)
        recordCue(cue)
        renderDiagnostics()
    }

    /** "Toca aquí para probar los cinco sonidos": uno cada 700 ms. */
    private fun playTestSequence() {
        handler.removeCallbacksAndMessages(TEST_SEQUENCE_TOKEN)
        val start = SystemClock.uptimeMillis()
        FeedbackCue.entries.forEachIndexed { index, cue ->
            handler.postAtTime({ cue(cue) }, TEST_SEQUENCE_TOKEN, start + index * 700L)
        }
    }

    private fun renderSignals() {
        signalList.removeAllViews()
        signalEmpty.visibility = if (signals.isEmpty()) View.VISIBLE else View.GONE
        signalList.visibility = if (signals.isEmpty()) View.GONE else View.VISIBLE
        if (signals.isEmpty()) return

        val zone = TimeZone.getTimeZone("America/Bogota")
        val time = SimpleDateFormat("HH:mm:ss", Locale("es", "CO")).apply { timeZone = zone }
        signals.forEachIndexed { index, signal ->
            if (index > 0) {
                val divider = View(this)
                divider.setBackgroundColor(getColor(R.color.eco_mist))
                val oneDp = (resources.displayMetrics.density).toInt().coerceAtLeast(1)
                signalList.addView(divider, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, oneDp))
            }
            val row = layoutInflater.inflate(R.layout.item_signal, signalList, false)
            val tile = row.findViewById<View>(R.id.signalIconTile)
            val icon = row.findViewById<ImageView>(R.id.signalIcon)
            val (tileBg, tint, iconRes) = when (signal.cue) {
                FeedbackCue.DETECTED, FeedbackCue.ACCEPTED ->
                    Triple(R.drawable.bg_icon_tile_mint, R.color.eco_primary_deep, R.drawable.ic_tab_speaker)
                FeedbackCue.RECYCLED ->
                    Triple(R.drawable.bg_icon_tile_success, R.color.eco_success_text, R.drawable.ic_check)
                FeedbackCue.REJECTED ->
                    Triple(R.drawable.bg_icon_tile_danger, R.color.eco_danger_text, R.drawable.ic_alert)
                FeedbackCue.NOT_RECYCLABLE ->
                    Triple(R.drawable.bg_icon_tile_warning, R.color.eco_warning_text, R.drawable.ic_alert)
            }
            tile.setBackgroundResource(tileBg)
            icon.setImageResource(iconRes)
            icon.setColorFilter(getColor(tint))
            row.findViewById<TextView>(R.id.signalTitle).setText(cueLabel(signal.cue))
            row.findViewById<TextView>(R.id.signalMeta).text =
                getString(R.string.speaker_signal_meta, time.format(Date(signal.atMs)))
            row.findViewById<TextView>(R.id.signalCue).text =
                getString(R.string.speaker_signal_cue, getString(soundLabel(signal.cue)))
            signalList.addView(row)
        }
    }

    private fun cueLabel(cue: FeedbackCue): Int = when (cue) {
        FeedbackCue.DETECTED -> R.string.cue_detected
        FeedbackCue.ACCEPTED -> R.string.cue_accepted
        FeedbackCue.REJECTED -> R.string.cue_rejected
        FeedbackCue.RECYCLED -> R.string.cue_recycled
        FeedbackCue.NOT_RECYCLABLE -> R.string.cue_not_recyclable
    }

    private fun soundLabel(cue: FeedbackCue): Int = when (cue) {
        FeedbackCue.DETECTED -> R.string.sound_detected
        FeedbackCue.ACCEPTED -> R.string.sound_accepted
        FeedbackCue.REJECTED -> R.string.sound_rejected
        FeedbackCue.RECYCLED -> R.string.sound_recycled
        FeedbackCue.NOT_RECYCLABLE -> R.string.sound_not_recyclable
    }

    // --------------------------------------------------------------- parlante

    private fun startSpeaker() {
        if (speakerRunning) return
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        volumeControlStream = AudioManager.STREAM_MUSIC
        sounds.startKeepAlive()
        bridge.start()
        speakerRunning = true
    }

    private fun stopSpeaker() {
        bridge.stop()
        sounds.stopKeepAlive()
        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        volumeControlStream = AudioManager.USE_DEFAULT_STREAM_TYPE
        speakerRunning = false
        feedStatus = ""
    }

    private fun updateSpeakerUi() {
        if (speakerSwitch.isChecked != settings.speakerEnabled) {
            speakerSwitch.isChecked = settings.speakerEnabled
        }
        val enabled = settings.speakerEnabled
        val email = settings.speakerEmail?.takeIf { it.isNotBlank() }

        if (!enabled) {
            speakerServerValue.text = "—"
            setStatusChip(speakerServerChip, R.string.speaker_off, R.drawable.bg_pill_mist, R.color.eco_slate, R.drawable.ic_dot_slate)
        } else {
            when {
                feedStatus.startsWith("EN VIVO") -> {
                    val endpoint = feedStatus.substringAfter('·').trim()
                    speakerServerValue.text = endpoint.ifEmpty { feedStatus }
                    setStatusChip(speakerServerChip, R.string.speaker_connected, R.drawable.bg_pill_mint, R.color.eco_success_text, R.drawable.ic_dot_green)
                }
                feedStatus.startsWith("servidor ") -> {
                    speakerServerValue.text = feedStatus.removePrefix("servidor ").substringBefore(": iniciando")
                    setStatusChip(speakerServerChip, R.string.speaker_searching, R.drawable.bg_pill_warning, R.color.eco_warning_text, R.drawable.ic_dot_amber)
                }
                feedStatus.startsWith("reintento") -> {
                    speakerServerValue.text = feedStatus
                    setStatusChip(speakerServerChip, R.string.speaker_retrying, R.drawable.bg_pill_warning, R.color.eco_warning_text, R.drawable.ic_dot_amber)
                }
                feedStatus.startsWith("buscando") -> {
                    speakerServerValue.text = feedStatus
                    setStatusChip(speakerServerChip, R.string.speaker_searching, R.drawable.bg_pill_warning, R.color.eco_warning_text, R.drawable.ic_dot_amber)
                }
                feedStatus.startsWith("falta") -> {
                    speakerServerValue.text = "—"
                    setStatusChip(speakerServerChip, R.string.speaker_no_account, R.drawable.bg_pill_mist, R.color.eco_slate, R.drawable.ic_dot_slate)
                }
                else -> {
                    speakerServerValue.text = "—"
                    setStatusChip(speakerServerChip, R.string.speaker_searching, R.drawable.bg_pill_warning, R.color.eco_warning_text, R.drawable.ic_dot_amber)
                }
            }
        }

        val output = sounds.outputDescription()
        speakerBluetoothValue.text = output
        if (output.startsWith("Bluetooth:")) {
            setStatusChip(speakerBluetoothChip, R.string.speaker_connected, R.drawable.bg_pill_mint, R.color.eco_success_text, R.drawable.ic_dot_green)
        } else {
            setStatusChip(speakerBluetoothChip, R.string.speaker_phone_output, R.drawable.bg_pill_mist, R.color.eco_slate, R.drawable.ic_dot_slate)
        }

        if (email == null) {
            speakerAccountValue.setText(R.string.speaker_account_missing)
            setStatusChip(speakerAccountChip, R.string.speaker_account_missing, R.drawable.bg_pill_mist, R.color.eco_slate, R.drawable.ic_dot_slate)
        } else {
            speakerAccountValue.text = email
            if (enabled) {
                setStatusChip(speakerAccountChip, R.string.speaker_account_active, R.drawable.bg_pill_mint, R.color.eco_success_text, R.drawable.ic_dot_green)
            } else {
                setStatusChip(speakerAccountChip, R.string.speaker_off, R.drawable.bg_pill_mist, R.color.eco_slate, R.drawable.ic_dot_slate)
            }
        }
    }

    private fun setStatusChip(chip: TextView, text: Int, background: Int, color: Int, dot: Int) {
        chip.setText(text)
        chip.setBackgroundResource(background)
        chip.setTextColor(getColor(color))
        chip.setCompoundDrawablesWithIntrinsicBounds(dot, 0, 0, 0)
    }

    // ---------------------------------------------------------------- reporte

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

    companion object {
        const val EXTRA_SECTION = "section"
        const val SECTION_CARD = "card"
        const val SECTION_SPEAKER = "speaker"

        private const val VERIFIED_HOLD_MS = 3_000L
        private const val AUTH_EVENT = "RETO -> 9000"
        private const val ENROLL_EVENT = "ENROLAR -> 9000"
        private const val ENROLL_ERROR_EVENT = "ENROLAR -> error"
        private const val SELECT_EVENT = "SELECCIONAR AID"
        private const val ENROLLMENT_TTL_MS = 60_000f
        private const val SIGNAL_LIMIT = 3
        private val TEST_SEQUENCE_TOKEN = Any()
    }
}
