package co.ecostation.app

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import android.os.SystemClock
import android.text.Editable
import android.text.InputType
import android.text.TextWatcher
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.Switch
import android.widget.TextView
import co.ecostation.app.net.BackendDiscovery
import co.ecostation.app.net.BoundedRead
import co.ecostation.app.net.EcoEndpoint
import co.ecostation.app.store.AppSettings
import co.ecostation.app.ui.BottomNav
import co.ecostation.app.ui.EcoTab
import java.net.HttpURLConnection
import java.net.URL

/**
 * Ajustes: servidor (dirección manual o mDNS) y cuenta del parlante.
 *
 * La contraseña nunca se muestra ni se vuelve a escribir en claro: si ya hay
 * una guardada, el campo queda vacío con una pista y solo se reemplaza cuando
 * la persona escribe una nueva. Una dirección no vacía que
 * [EcoEndpoint.parseManual] rechaza no guarda nada.
 */
class SettingsActivity : Activity() {

    private lateinit var settings: AppSettings
    private lateinit var discovery: BackendDiscovery
    private lateinit var address: EditText
    private lateinit var autoDiscover: Switch
    private lateinit var email: EditText
    private lateinit var password: EditText
    private lateinit var error: TextView
    private lateinit var testButton: Button
    private lateinit var connectionStatus: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)
        settings = AppSettings(this)
        discovery = BackendDiscovery(this)

        address = findViewById(R.id.serverAddress)
        autoDiscover = findViewById(R.id.autoDiscover)
        email = findViewById(R.id.speakerEmail)
        password = findViewById(R.id.speakerPassword)
        error = findViewById(R.id.addressError)
        testButton = findViewById(R.id.testConnection)
        connectionStatus = findViewById(R.id.connectionStatus)

        address.setText(settings.serverAddress.orEmpty())
        autoDiscover.isChecked = settings.serverAddress.isNullOrBlank()
        email.setText(settings.speakerEmail.orEmpty())

        password.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        password.hint = getString(
            if (settings.speakerPassword != null) R.string.settings_password_saved
            else R.string.settings_password_hint,
        )

        autoDiscover.setOnCheckedChangeListener { _, checked ->
            if (checked) {
                address.setText("")
                error.visibility = View.GONE
            } else {
                address.requestFocus()
            }
        }
        // Escribir una dirección a mano implica no buscar automáticamente.
        // Se escucha el texto, no el foco: el campo puede recibir foco solo al
        // abrir la pantalla y no queremos apagar la búsqueda sin que nadie escriba.
        address.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) = Unit
            override fun afterTextChanged(s: Editable?) {
                if (!s.isNullOrBlank() && autoDiscover.isChecked) autoDiscover.isChecked = false
            }
        })

        testButton.setOnClickListener { testConnection() }
        findViewById<Button>(R.id.saveSettings).setOnClickListener { save() }

        findViewById<TextView>(R.id.topBarTitle).setText(R.string.settings_title)
        BottomNav.bind(this, EcoTab.SETTINGS)
    }

    /**
     * Una dirección no vacía que [EcoEndpoint.parseManual] rechaza no guarda
     * nada: se avisa aquí y no se deja el error para el momento de conectar.
     *
     * El sellado del correo/contraseña usa el Keystore y puede fallar en el
     * hilo de la interfaz; se escribe en un hilo normal y, si el almacén
     * seguro no responde, se muestra un error en español en vez de tumbar la
     * app. Guardar «Buscar automáticamente» borra la dirección fijada.
     */
    private fun save() {
        val typed = address.text.toString().trim()
        if (!autoDiscover.isChecked && typed.isEmpty()) {
            error.setText(R.string.settings_bad_address)
            error.visibility = View.VISIBLE
            return
        }
        val endpoint = if (autoDiscover.isChecked) null else EcoEndpoint.parseManual(typed)
        if (!autoDiscover.isChecked && endpoint == null) {
            error.setText(R.string.settings_bad_address)
            error.visibility = View.VISIBLE
            return
        }
        error.visibility = View.GONE

        val saveButton = findViewById<Button>(R.id.saveSettings)
        saveButton.isEnabled = false
        val addressValue = endpoint?.toString()
        val emailValue = email.text.toString().trim().ifEmpty { null }
        val newPassword = password.text.toString()

        Thread({
            var saved = true
            try {
                settings.serverAddress = addressValue
                settings.speakerEmail = emailValue
                if (newPassword.isNotEmpty()) settings.speakerPassword = newPassword
                // «Iniciar sesión del parlante» deja el modo encendido y vuelve
                // a la pestaña Parlante, donde el interruptor queda a la vista.
                settings.speakerEnabled = true
            } catch (e: Exception) {
                saved = false
            }
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                saveButton.isEnabled = true
                if (!saved) {
                    error.setText(R.string.settings_save_failed)
                    error.visibility = View.VISIBLE
                    return@runOnUiThread
                }
                startActivity(
                    Intent(this, MainActivity::class.java)
                        .putExtra(MainActivity.EXTRA_SECTION, MainActivity.SECTION_SPEAKER)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP),
                )
                finish()
            }
        }, "eco-settings-save").apply { isDaemon = true; start() }
    }

    /**
     * Prueba de conectividad: descubre o usa la dirección escrita y pide
     * `/manifest.webmanifest` con timeouts cortos. Corre en un hilo propio y
     * nunca lanza: sin red solo muestra «Sin conexión».
     */
    private fun testConnection() {
        val typed = if (autoDiscover.isChecked) null else address.text.toString().trim().ifEmpty { null }
        if (!autoDiscover.isChecked && typed == null) {
            error.setText(R.string.settings_bad_address)
            error.visibility = View.VISIBLE
            return
        }
        if (typed != null && EcoEndpoint.parseManual(typed) == null) {
            error.setText(R.string.settings_bad_address)
            error.visibility = View.VISIBLE
            return
        }
        error.visibility = View.GONE
        testButton.isEnabled = false
        setStatus(getString(R.string.settings_testing), R.drawable.ic_dot_amber, R.color.eco_warning_text)

        Thread({
            var latency = -1L
            try {
                val endpoint = EcoEndpoint.locate(typed) { discovery.discover { } }
                if (endpoint != null) {
                    val started = SystemClock.elapsedRealtime()
                    var conn: HttpURLConnection? = null
                    try {
                        val c = URL(endpoint.baseUrl + "/manifest.webmanifest")
                            .openConnection() as HttpURLConnection
                        conn = c
                        c.connectTimeout = BoundedRead.MANIFEST_TIMEOUT_MS.toInt()
                        c.readTimeout = BoundedRead.MANIFEST_TIMEOUT_MS.toInt()
                        c.instanceFollowRedirects = false
                        val code = c.responseCode
                        val body = BoundedRead.read(
                            if (code == 200) c.inputStream else c.errorStream,
                            BoundedRead.SMALL_MAX,
                            BoundedRead.MANIFEST_TIMEOUT_MS,
                        )
                        if (code == 200 && EcoEndpoint.isEcoStationManifest(body)) {
                            latency = SystemClock.elapsedRealtime() - started
                        }
                    } finally {
                        conn?.disconnect()
                    }
                }
            } catch (_: Exception) {
                // Sin red, DNS roto o timeout: se informa abajo, nunca se lanza.
            }
            val ok = latency >= 0
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                testButton.isEnabled = true
                if (ok) {
                    setStatus(
                        getString(R.string.settings_connected, latency),
                        R.drawable.ic_dot_green,
                        R.color.eco_success_text,
                    )
                } else {
                    setStatus(getString(R.string.settings_no_connection), R.drawable.ic_dot_red, R.color.eco_danger_text)
                }
            }
        }, "eco-test").apply { isDaemon = true; start() }
    }

    private fun setStatus(text: String, dot: Int, color: Int) {
        connectionStatus.text = text
        connectionStatus.setTextColor(getColor(color))
        connectionStatus.setCompoundDrawablesWithIntrinsicBounds(dot, 0, 0, 0)
    }
}
