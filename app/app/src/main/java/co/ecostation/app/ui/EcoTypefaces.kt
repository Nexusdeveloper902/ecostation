package co.ecostation.app.ui

import android.content.Context
import android.graphics.Typeface
import android.os.Build
import android.util.AttributeSet
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import co.ecostation.app.R

/** Las seis caras que usa el sistema de diseño. */
enum class EcoFace(val fontRes: Int, val asset: String) {
    DISPLAY(R.font.fredoka_600, "fonts/fredoka_600.ttf"),
    HEADING(R.font.fredoka_500, "fonts/fredoka_500.ttf"),
    BODY(R.font.nunito_400, "fonts/nunito_400.ttf"),
    BOLD(R.font.nunito_700, "fonts/nunito_700.ttf"),
    BLACK(R.font.nunito_800, "fonts/nunito_800.ttf"),
    MONO(R.font.jetbrains_mono_600, "fonts/jetbrains_mono_600.ttf"),
    ;

    companion object {
        fun fromAttr(value: Int): EcoFace? = entries.getOrNull(value)
    }
}

/**
 * Carga las fuentes autoalojadas sin AndroidX.
 *
 * `res/font` y `android:fontFamily="@font/…"` son una función del framework
 * desde API 26. Como `minSdk` es 24, en 24–25 se carga la misma TTF desde
 * `assets/fonts`; en 26+ se usa el recurso. El resultado visual es idéntico.
 */
object EcoTypeface {

    private val cache = HashMap<EcoFace, Typeface>()

    fun of(context: Context, face: EcoFace): Typeface = synchronized(cache) {
        cache.getOrPut(face) {
            if (Build.VERSION.SDK_INT >= 26) {
                context.resources.getFont(face.fontRes)
            } else {
                Typeface.createFromAsset(context.assets, face.asset)
            }
        }
    }

    fun apply(view: TextView, context: Context, attrs: AttributeSet?) {
        if (attrs == null) return
        val a = context.obtainStyledAttributes(attrs, R.styleable.EcoFont)
        val value = a.getInt(R.styleable.EcoFont_ecoFont, -1)
        a.recycle()
        EcoFace.fromAttr(value)?.let { view.typeface = of(context, it) }
    }
}

/** TextView que entiende `app:ecoFont` (y por tanto los estilos de Eco). */
class EcoTextView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = android.R.attr.textViewStyle,
) : TextView(context, attrs, defStyleAttr) {
    init {
        EcoTypeface.apply(this, context, attrs)
    }
}

/** Button con la misma fuente semántica. */
class EcoButton @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = android.R.attr.buttonStyle,
) : Button(context, attrs, defStyleAttr) {
    init {
        EcoTypeface.apply(this, context, attrs)
    }
}

/** EditText con la misma fuente semántica. */
class EcoEditText @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = android.R.attr.editTextStyle,
) : EditText(context, attrs, defStyleAttr) {
    init {
        EcoTypeface.apply(this, context, attrs)
    }
}
