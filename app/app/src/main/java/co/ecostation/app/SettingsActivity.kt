package co.ecostation.app

import android.app.Activity
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.Switch
import android.widget.TextView
import co.ecostation.app.net.EcoEndpoint
import co.ecostation.app.store.AppSettings

/**
 * Formulario plano de ajustes: dirección manual del servidor, interruptor de
 * modo parlante y la cuenta de la estación. La contraseña nunca se muestra ni
 * se vuelve a escribir en claro: si ya hay una guardada, el campo queda vacío
 * con una pista y solo se reemplaza cuando la persona escribe una nueva.
 */
class SettingsActivity : Activity() {

    private lateinit var settings: AppSettings
    private lateinit var address: EditText
    private lateinit var speaker: Switch
    private lateinit var email: EditText
    private lateinit var password: EditText
    private lateinit var error: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)
        settings = AppSettings(this)

        address = findViewById(R.id.serverAddress)
        speaker = findViewById(R.id.speakerEnabled)
        email = findViewById(R.id.speakerEmail)
        password = findViewById(R.id.speakerPassword)
        error = findViewById(R.id.addressError)
        findViewById<Button>(R.id.saveSettings).setOnClickListener { save() }

        address.setText(settings.serverAddress.orEmpty())
        speaker.isChecked = settings.speakerEnabled
        email.setText(settings.speakerEmail.orEmpty())

        password.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        password.hint = getString(
            if (settings.speakerPassword != null) R.string.settings_password_saved
            else R.string.settings_password_hint,
        )
    }

    /**
     * Una dirección no vacía que [EcoEndpoint.parseManual] rechaza no guarda
     * nada: se avisa aquí y no se deja el error para el momento de conectar.
     */
    private fun save() {
        val typed = address.text.toString().trim()
        val endpoint = if (typed.isEmpty()) null else EcoEndpoint.parseManual(typed)
        if (typed.isNotEmpty() && endpoint == null) {
            error.setText(R.string.settings_bad_address)
            error.visibility = View.VISIBLE
            return
        }
        error.visibility = View.GONE

        settings.serverAddress = endpoint?.toString()
        settings.speakerEnabled = speaker.isChecked
        settings.speakerEmail = email.text.toString().trim().ifEmpty { null }
        val newPassword = password.text.toString()
        if (newPassword.isNotEmpty()) settings.speakerPassword = newPassword
        finish()
    }
}
