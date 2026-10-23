package co.ecostation.app.ui

import android.content.Context
import android.util.AttributeSet
import android.widget.FrameLayout

/**
 * Un marco que siempre mide la proporción de una credencial real.
 *
 * ISO/IEC 7810 ID-1 —el formato de toda tarjeta bancaria, pase de transporte o
 * carné escolar— mide 85,60 × 53,98 mm, una razón de 1,5858. El teléfono hace
 * de carné físico, así que mantiene esa proporción a cualquier ancho de
 * pantalla en lugar de una altura adivinada.
 *
 * Es una vista propia y no `dimensionRatio` de ConstraintLayout porque la app
 * no usa AndroidX: este era el único sitio que necesitaba la proporción.
 */
class CredentialCardView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0,
) : FrameLayout(context, attrs, defStyleAttr) {

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val width = MeasureSpec.getSize(widthMeasureSpec)
        val height = (width / ID1_ASPECT).toInt()
        super.onMeasure(
            MeasureSpec.makeMeasureSpec(width, MeasureSpec.EXACTLY),
            MeasureSpec.makeMeasureSpec(height, MeasureSpec.EXACTLY),
        )
    }

    private companion object {
        const val ID1_ASPECT = 1.5858f
    }
}
