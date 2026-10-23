package co.ecostation.app

import android.app.Activity
import android.os.Bundle
import android.text.InputType
import android.text.SpannableString
import android.text.style.RelativeSizeSpan
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.ListView
import android.widget.TextView
import co.ecostation.app.net.BackendDiscovery
import co.ecostation.app.net.EcoEndpoint
import co.ecostation.app.student.ApiException
import co.ecostation.app.student.Balance
import co.ecostation.app.student.Deposit
import co.ecostation.app.student.StudentApi
import co.ecostation.app.store.AppSettings
import co.ecostation.app.ui.BottomNav
import co.ecostation.app.ui.DepositAdapter
import co.ecostation.app.ui.EcoTab
import java.io.IOException
import java.util.Locale

/**
 * Vista del estudiante: entrar, cambiar la contraseña cuando el servidor lo
 * exige, y ver saldo, puesto e historial de depósitos.
 *
 * Todo el trabajo de red corre en un hilo normal; los resultados vuelven con
 * `runOnUiThread`. Sin corrutinas y sin AndroidX: un solo hilo por operación y
 * los botones se deshabilitan mientras hay una petición en vuelo.
 */
class StudentActivity : Activity() {

    private lateinit var settings: AppSettings
    private lateinit var discovery: BackendDiscovery
    private var endpoint: EcoEndpoint? = null

    private lateinit var loginGroup: View
    private lateinit var loadingGroup: View
    private lateinit var changeGroup: View
    private lateinit var accountGroup: View

    private lateinit var loginEmail: EditText
    private lateinit var loginPassword: EditText
    private lateinit var loginButton: Button
    private lateinit var loginError: TextView
    private lateinit var loginLogoutButton: Button

    private lateinit var changeCurrent: EditText
    private lateinit var changeNew: EditText
    private lateinit var changeConfirm: EditText
    private lateinit var changeButton: Button
    private lateinit var changeError: TextView
    private lateinit var changeLogoutButton: Button

    private lateinit var studentName: TextView
    private lateinit var studentClass: TextView
    private lateinit var balanceText: TextView
    private lateinit var rankText: TextView
    private lateinit var accountError: TextView
    private lateinit var historyList: ListView
    private lateinit var emptyHistory: TextView
    private lateinit var moreButton: Button
    private lateinit var logoutButton: Button

    private val rows = ArrayList<Deposit>()
    private lateinit var rowAdapter: DepositAdapter
    private var page = 1
    private var hasMore = false

    /**
     * Incremented whenever a new session action starts. A background result
     * from an older action (a stored-token check, a page load) checks this
     * before touching the views, so it cannot hide a login the person just
     * started.
     */
    private var epoch = 0

    private fun nextEpoch(): Int = ++epoch

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_student)
        settings = AppSettings(this)
        discovery = BackendDiscovery(this)

        loginGroup = findViewById(R.id.loginGroup)
        loadingGroup = findViewById(R.id.loadingGroup)
        changeGroup = findViewById(R.id.changeGroup)
        accountGroup = findViewById(R.id.accountGroup)

        loginEmail = findViewById(R.id.loginEmail)
        loginEmail.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
        loginPassword = findViewById(R.id.loginPassword)
        loginPassword.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        loginButton = findViewById(R.id.loginButton)
        loginError = findViewById(R.id.loginError)
        loginLogoutButton = findViewById(R.id.loginLogout)

        changeCurrent = findViewById(R.id.changeCurrent)
        changeCurrent.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        changeNew = findViewById(R.id.changeNew)
        changeNew.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        changeConfirm = findViewById(R.id.changeConfirm)
        changeConfirm.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        changeButton = findViewById(R.id.changeButton)
        changeError = findViewById(R.id.changeError)
        changeLogoutButton = findViewById(R.id.changeLogout)

        studentName = findViewById(R.id.studentName)
        studentClass = findViewById(R.id.studentClass)
        balanceText = findViewById(R.id.balanceText)
        rankText = findViewById(R.id.rankText)
        accountError = findViewById(R.id.accountError)
        historyList = findViewById(R.id.historyList)
        emptyHistory = findViewById(R.id.emptyHistory)
        moreButton = findViewById(R.id.moreButton)
        logoutButton = findViewById(R.id.logoutButton)

        rowAdapter = DepositAdapter(this)
        historyList.adapter = rowAdapter

        findViewById<TextView>(R.id.topBarTitle).setText(R.string.student_title)
        BottomNav.bind(this, EcoTab.POINTS)

        loginButton.setOnClickListener { login() }
        changeButton.setOnClickListener { changePassword() }
        moreButton.setOnClickListener { loadMore() }
        logoutButton.setOnClickListener { logout() }
        loginLogoutButton.setOnClickListener { logout() }
        changeLogoutButton.setOnClickListener { logout() }
    }

    /** Relanzar con token guardado entra directo; sin token, al formulario. */
    override fun onStart() {
        super.onStart()
        endpoint = null
        val token = settings.studentToken
        if (token.isNullOrBlank()) {
            showLogin(null)
            return
        }
        // Validating a stored token can take a network round-trip: show the
        // loading state, and ignore its result if the person starts a login
        // (or logs out) meanwhile.
        val expected = nextEpoch()
        showLoading()
        background {
            val api = api()
            if (expected != epoch) return@background
            if (api == null) {
                ui {
                    if (expected != epoch) return@ui
                    showAccountError(R.string.student_error_no_server)
                }
                return@background
            }
            loadAccount(api, token, expected)
        }
    }

    // ---------------------------------------------------------------- login

    private fun login() {
        val email = loginEmail.text.toString().trim()
        val password = loginPassword.text.toString()
        val expected = nextEpoch()
        loginButton.isEnabled = false
        loginError.visibility = View.GONE

        background {
            val api = api()
            if (api == null) {
                ui {
                    if (expected != epoch) return@ui
                    loginButton.isEnabled = true
                    loginError.setText(R.string.student_error_no_server)
                    loginError.visibility = View.VISIBLE
                }
                return@background
            }
            try {
                val result = api.login(email, password)
                // Se guarda de inmediato, diga lo que diga must_change_password.
                setToken(result.token)
                ui {
                    if (expected != epoch) return@ui
                    loginPassword.text.clear()
                    loginButton.isEnabled = true
                }
                if (result.mustChangePassword) {
                    ui { if (expected == epoch) showChange() }
                } else {
                    loadAccount(api, result.token, expected)
                }
            } catch (e: ApiException) {
                ui {
                    if (expected != epoch) return@ui
                    loginPassword.text.clear()
                    loginButton.isEnabled = true
                    loginError.setText(loginMessage(e))
                    loginError.visibility = View.VISIBLE
                }
            } catch (e: IOException) {
                ui {
                    if (expected != epoch) return@ui
                    loginPassword.text.clear()
                    loginButton.isEnabled = true
                    loginError.setText(R.string.student_error_server)
                    loginError.visibility = View.VISIBLE
                }
            }
        }
    }

    // ------------------------------------------------------------ contraseña

    private fun changePassword() {
        val token = settings.studentToken
        if (token.isNullOrBlank()) {
            showLogin(getString(R.string.student_error_session))
            return
        }
        val current = changeCurrent.text.toString()
        val new = changeNew.text.toString()
        val confirm = changeConfirm.text.toString()
        if (new != confirm) {
            changeError.setText(R.string.student_password_mismatch)
            changeError.visibility = View.VISIBLE
            return
        }
        val expected = epoch
        changeButton.isEnabled = false
        changeError.visibility = View.GONE

        background {
            val api = api()
            if (api == null) {
                ui {
                    if (expected != epoch) return@ui
                    changeButton.isEnabled = true
                    changeError.setText(R.string.student_error_no_server)
                    changeError.visibility = View.VISIBLE
                }
                return@background
            }
            try {
                api.changePassword(token, current, new, confirm)
                ui {
                    if (expected != epoch) return@ui
                    changeCurrent.text.clear()
                    changeNew.text.clear()
                    changeConfirm.text.clear()
                    changeButton.isEnabled = true
                }
                loadAccount(api, token, expected)
            } catch (e: ApiException) {
                ui {
                    if (expected != epoch) return@ui
                    changeButton.isEnabled = true
                    if (e.kind == ApiException.Kind.UNAUTHORIZED) {
                        setToken(null)
                        endpoint = null
                        showLogin(getString(R.string.student_error_session))
                    } else {
                        // VALIDATION trae el mensaje en español del servidor.
                        changeError.setText(e.message ?: getString(R.string.student_error_server))
                        changeError.visibility = View.VISIBLE
                    }
                }
            } catch (e: IOException) {
                ui {
                    if (expected != epoch) return@ui
                    changeButton.isEnabled = true
                    changeError.setText(R.string.student_error_server)
                    changeError.visibility = View.VISIBLE
                }
            }
        }
    }

    // --------------------------------------------------------------- cuenta

    /** Corre en el hilo de fondo; publica el resultado en el hilo de UI. */
    private fun loadAccount(api: StudentApi, token: String, expectedEpoch: Int) {
        try {
            val balance = api.balance(token)
            val history = api.history(token, 1)
            ui {
                if (expectedEpoch != epoch) return@ui
                page = 1
                hasMore = history.hasMore
                rows.clear()
                rows.addAll(history.items)
                rowAdapter.setItems(rows)
                renderAccount(balance)
            }
        } catch (e: ApiException) {
            ui {
                if (expectedEpoch != epoch) return@ui
                handleAccountError(e)
            }
        } catch (e: IOException) {
            ui {
                if (expectedEpoch != epoch) return@ui
                showAccountError(R.string.student_error_server)
            }
        }
    }

    private fun loadMore() {
        val token = settings.studentToken
        if (token.isNullOrBlank()) {
            showLogin(getString(R.string.student_error_session))
            return
        }
        val next = page + 1
        val expected = epoch
        moreButton.isEnabled = false
        background {
            val api = api()
            if (api == null) {
                ui {
                    if (expected != epoch) return@ui
                    moreButton.isEnabled = true
                    showAccountError(R.string.student_error_no_server)
                }
                return@background
            }
            try {
                val history = api.history(token, next)
                ui {
                    if (expected != epoch) return@ui
                    page = next
                    hasMore = history.hasMore
                    rows.addAll(history.items)
                    rowAdapter.addItems(history.items)
                    moreButton.isEnabled = true
                    moreButton.visibility = if (hasMore) View.VISIBLE else View.GONE
                }
            } catch (e: ApiException) {
                ui {
                    if (expected != epoch) return@ui
                    moreButton.isEnabled = true
                    handleAccountError(e)
                }
            } catch (e: IOException) {
                ui {
                    if (expected != epoch) return@ui
                    moreButton.isEnabled = true
                    showAccountError(R.string.student_error_server)
                }
            }
        }
    }

    /**
     * Un token rechazado se borra y se vuelve al formulario; el resto de
     * errores se muestran sin tocar la sesión.
     */
    private fun handleAccountError(e: ApiException) {
        when (e.kind) {
            ApiException.Kind.UNAUTHORIZED -> {
                setToken(null)
                endpoint = null
                showLogin(getString(R.string.student_error_session))
            }
            ApiException.Kind.PASSWORD_CHANGE_REQUIRED -> showChange()
            ApiException.Kind.NOT_LINKED ->
                showAccountError(R.string.student_error_not_linked)
            ApiException.Kind.THROTTLED ->
                showAccountError(R.string.student_error_throttled)
            else -> showAccountError(R.string.student_error_server)
        }
    }

    /**
     * Sale primero en local (el token se borra antes de la red); el borrado en
     * el servidor es best-effort. El correo escrito se conserva para volver a
     * entrar, y la llave HCE nunca se toca.
     */
    private fun logout() {
        val token = settings.studentToken
        setToken(null)
        endpoint = null
        showLogin(null)
        if (token.isNullOrBlank()) return
        background {
            try {
                api()?.logout(token)
            } catch (_: Exception) {
                // Sin red o ya revocado: la sesión local ya terminó igual.
            }
        }
    }

    // ---------------------------------------------------------------- render

    private fun renderAccount(balance: Balance) {
        loadingGroup.visibility = View.GONE
        loginGroup.visibility = View.GONE
        changeGroup.visibility = View.GONE
        accountGroup.visibility = View.VISIBLE
        accountError.visibility = View.GONE

        studentName.text = balance.student.name
        studentClass.text = balance.student.className.orEmpty()
        studentClass.visibility = if (balance.student.className.isNullOrBlank()) View.GONE else View.VISIBLE
        val figure = formatPoints(balance.balance)
        val full = getString(R.string.student_balance, figure)
        balanceText.text = SpannableString(full).apply {
            setSpan(RelativeSizeSpan(0.45f), figure.length, full.length, 0)
        }
        if (balance.rank == null) {
            rankText.visibility = View.GONE
        } else {
            rankText.text = getString(R.string.student_rank, balance.rank)
            rankText.visibility = View.VISIBLE
        }
        emptyHistory.visibility = if (rows.isEmpty()) View.VISIBLE else View.GONE
        historyList.visibility = if (rows.isEmpty()) View.GONE else View.VISIBLE
        moreButton.visibility = if (hasMore) View.VISIBLE else View.GONE
        moreButton.isEnabled = true
    }

    private fun formatPoints(value: Int): String =
        String.format(Locale.US, "%,d", value).replace(',', '.')

    private fun showAccountError(messageRes: Int) {
        loadingGroup.visibility = View.GONE
        loginGroup.visibility = View.GONE
        changeGroup.visibility = View.GONE
        accountGroup.visibility = View.VISIBLE
        accountError.setText(messageRes)
        accountError.visibility = View.VISIBLE
    }

    private fun showLoading() {
        loadingGroup.visibility = View.VISIBLE
        loginGroup.visibility = View.GONE
        changeGroup.visibility = View.GONE
        accountGroup.visibility = View.GONE
    }

    private fun showLogin(message: String?) {
        // Cualquier respuesta en vuelo pertenece a una acción anterior.
        nextEpoch()
        loadingGroup.visibility = View.GONE
        loginGroup.visibility = View.VISIBLE
        changeGroup.visibility = View.GONE
        accountGroup.visibility = View.GONE
        loginButton.isEnabled = true
        loginPassword.text.clear()
        if (message.isNullOrBlank()) {
            loginError.visibility = View.GONE
        } else {
            loginError.text = message
            loginError.visibility = View.VISIBLE
        }
    }

    private fun showChange() {
        loadingGroup.visibility = View.GONE
        loginGroup.visibility = View.GONE
        changeGroup.visibility = View.VISIBLE
        accountGroup.visibility = View.GONE
        changeButton.isEnabled = true
        changeError.visibility = View.GONE
    }

    // ---------------------------------------------------------------- apoyo

    private fun api(): StudentApi? {
        endpoint?.let { return StudentApi(it) }
        // El descubrimiento es de la plataforma (NSD/sockets): un fallo raro de
        // un OEM no debe tumbar el hilo de fondo, solo dejar la vista sin servidor.
        val found = try {
            EcoEndpoint.locate(settings.serverAddress) { discovery.discover { } }
        } catch (_: Exception) {
            null
        } ?: return null
        endpoint = found
        return StudentApi(found)
    }

    private fun loginMessage(e: ApiException): String = when (e.kind) {
        ApiException.Kind.BAD_CREDENTIALS -> getString(R.string.student_error_credentials)
        ApiException.Kind.THROTTLED -> getString(R.string.student_error_throttled)
        ApiException.Kind.UNAUTHORIZED -> getString(R.string.student_error_session)
        else -> getString(R.string.student_error_server)
    }

    private fun background(work: () -> Unit) {
        Thread(work, "eco-student").apply { isDaemon = true; start() }
    }

    /**
     * El sellado usa el Keystore desde el hilo de fondo. Si el Keystore falla,
     * la sesión sigue viva en memoria pero no se persiste: nunca se tumba la
     * app por no poder guardar el token.
     */
    private fun setToken(value: String?) {
        try {
            settings.studentToken = value
        } catch (_: Exception) {
        }
    }

    private fun ui(block: () -> Unit) = runOnUiThread(block)
}
