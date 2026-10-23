package co.ecostation.app.ui

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.util.AttributeSet
import android.view.View
import co.ecostation.app.R
import java.util.Locale
import kotlin.math.min

/**
 * El blanco de toque de la tarjeta: tres anillos concéntricos de menta y, en
 * el centro, el icono de teléfono (estado normal) o la cuenta atrás del
 * emparejamiento con su arco de progreso (estado VINCULANDO).
 *
 * Se dibuja a mano para no añadir dependencias ni nueve `View` superpuestas.
 */
class TapTargetView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0,
) : View(context, attrs, defStyleAttr) {

    private val ringOuter = context.getColor(R.color.eco_success_bg)
    private val ringMiddle = Color.rgb(216, 242, 231)
    private val ringInner = context.getColor(R.color.eco_white)
    private val mist = context.getColor(R.color.eco_mist)
    private val ink = context.getColor(R.color.eco_ink)
    private val accent = context.getColor(R.color.eco_secondary_deep)

    private val fill = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.FILL }
    private val stroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.STROKE
        strokeCap = Paint.Cap.ROUND
        strokeJoin = Paint.Join.ROUND
    }
    private val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = ink
        textAlign = Paint.Align.CENTER
        typeface = EcoTypeface.of(context, EcoFace.MONO)
    }

    private val oval = RectF()
    private val phoneRect = RectF()

    private var countdown = false
    private var seconds = 0
    private var progress = 0f

    /** Estado normal: teléfono dentro del anillo interior. */
    fun showPhone() {
        if (!countdown) return
        countdown = false
        invalidate()
    }

    /** Estado VINCULANDO: cuenta atrás y arco que se vacía. */
    fun showCountdown(seconds: Int, progress: Float) {
        this.seconds = seconds
        this.progress = progress.coerceIn(0f, 1f)
        if (!countdown) countdown = true
        invalidate()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val cx = width / 2f
        val cy = height / 2f
        val maxR = min(width, height) / 2f - dp(2f)

        fill.color = ringOuter
        canvas.drawCircle(cx, cy, maxR, fill)
        fill.color = ringMiddle
        canvas.drawCircle(cx, cy, maxR * 0.78f, fill)
        fill.color = ringInner
        canvas.drawCircle(cx, cy, maxR * 0.57f, fill)

        if (countdown) {
            val arcRadius = maxR * 0.57f - dp(5f)
            stroke.color = mist
            stroke.strokeWidth = dp(6f)
            canvas.drawCircle(cx, cy, arcRadius, stroke)
            stroke.color = accent
            oval.set(cx - arcRadius, cy - arcRadius, cx + arcRadius, cy + arcRadius)
            canvas.drawArc(oval, -90f, 360f * progress, false, stroke)

            textPaint.textSize = dp(28f)
            val metrics = textPaint.fontMetrics
            val baseline = cy - (metrics.ascent + metrics.descent) / 2f
            val mm = seconds / 60
            val ss = seconds % 60
            canvas.drawText(String.format(Locale.ROOT, "%d:%02d", mm, ss), cx, baseline, textPaint)
        } else {
            drawPhone(canvas, cx, cy, maxR * 0.57f)
        }
    }

    private fun drawPhone(canvas: Canvas, cx: Float, cy: Float, innerR: Float) {
        val w = innerR * 0.36f
        val h = innerR * 0.62f
        val left = cx - w / 2f
        val top = cy - h / 2f
        val right = cx + w / 2f
        val bottom = cy + h / 2f
        stroke.color = ink
        stroke.strokeWidth = dp(2f)
        val radius = w * 0.22f
        phoneRect.set(left, top, right, bottom)
        canvas.drawRoundRect(phoneRect, radius, radius, stroke)
        val lineY = bottom - h * 0.14f
        canvas.drawLine(cx - w * 0.16f, lineY, cx + w * 0.16f, lineY, stroke)
    }

    private fun dp(value: Float): Float = value * resources.displayMetrics.density
}
