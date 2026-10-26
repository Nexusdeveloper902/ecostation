package co.ecostation.app.ui

import android.app.Activity
import android.content.Intent
import android.graphics.PorterDuff
import android.view.View
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import co.ecostation.app.MainActivity
import co.ecostation.app.R
import co.ecostation.app.SettingsActivity
import co.ecostation.app.StudentActivity

/** Las cuatro destinations reales de la barra inferior. */
enum class EcoTab(val labelRes: Int, val iconRes: Int) {
    CARD(R.string.nav_card, R.drawable.ic_tab_card),
    SPEAKER(R.string.nav_speaker, R.drawable.ic_tab_speaker),
    POINTS(R.string.nav_points, R.drawable.ic_tab_points),
    SETTINGS(R.string.nav_settings, R.drawable.ic_tab_settings),
}

/**
 * Pinta la barra inferior compartida y resuelve sus cuatro destinos.
 *
 * `Tarjeta` y `Parlante` son dos secciones de [MainActivity]; `Mis puntos` y
 * `Ajustes` son actividades. Para no encadenar actividades ni dejar un
 * back-stack circular, al cambiar de destino se abre la nueva pantalla y se
 * termina la actual: la barra se comporta como pestañas, no como un historial.
 */
object BottomNav {

    fun bind(activity: Activity, active: EcoTab, onSelect: ((EcoTab) -> Unit)? = null) {
        val items = listOf(
            Triple(EcoTab.CARD, R.id.navCard, R.id.navCardIcon to R.id.navCardLabel),
            Triple(EcoTab.SPEAKER, R.id.navSpeaker, R.id.navSpeakerIcon to R.id.navSpeakerLabel),
            Triple(EcoTab.POINTS, R.id.navPoints, R.id.navPointsIcon to R.id.navPointsLabel),
            Triple(EcoTab.SETTINGS, R.id.navSettings, R.id.navSettingsIcon to R.id.navSettingsLabel),
        )
        for ((tab, itemId, iconAndLabel) in items) {
            val item = activity.findViewById<LinearLayout>(itemId) ?: continue
            val icon = activity.findViewById<ImageView>(iconAndLabel.first) ?: continue
            val label = activity.findViewById<TextView>(iconAndLabel.second) ?: continue
            val isActive = tab == active
            item.setBackgroundResource(if (isActive) R.drawable.bg_nav_active else R.drawable.bg_nav_item)
            val color = activity.getColor(if (isActive) R.color.eco_primary_strong else R.color.eco_slate)
            icon.setColorFilter(color, PorterDuff.Mode.SRC_IN)
            label.setTextColor(color)
            item.contentDescription = activity.getString(tab.labelRes)
            item.isSelected = isActive
            item.setOnClickListener {
                if (tab == active) return@setOnClickListener
                if (onSelect != null) onSelect(tab) else navigate(activity, tab)
            }
            item.visibility = View.VISIBLE
        }
    }

    private fun navigate(activity: Activity, tab: EcoTab) {
        val intent = when (tab) {
            EcoTab.CARD -> Intent(activity, MainActivity::class.java)
                .putExtra(MainActivity.EXTRA_SECTION, MainActivity.SECTION_CARD)
            EcoTab.SPEAKER -> Intent(activity, MainActivity::class.java)
                .putExtra(MainActivity.EXTRA_SECTION, MainActivity.SECTION_SPEAKER)
            EcoTab.POINTS -> Intent(activity, StudentActivity::class.java)
            EcoTab.SETTINGS -> Intent(activity, SettingsActivity::class.java)
        }
        if (tab == EcoTab.CARD || tab == EcoTab.SPEAKER) {
            intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        }
        activity.startActivity(intent)
        activity.finish()
    }
}
