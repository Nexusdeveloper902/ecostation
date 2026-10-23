package co.ecostation.app.ui

import android.content.Context
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.BaseAdapter
import android.widget.TextView
import co.ecostation.app.R
import co.ecostation.app.student.Deposit
import co.ecostation.app.student.displayTime
import co.ecostation.app.student.materialLabel

/**
 * Adaptador de las filas de historial. Sustituye al `ArrayAdapter<String>` de
 * la primera versión por una fila con chip de material, hora y pastilla de
 * puntos. La paginación y el modelo siguen siendo los de [Deposit].
 */
class DepositAdapter(context: Context) : BaseAdapter() {

    private val app = context.applicationContext
    private val inflater = LayoutInflater.from(app)
    private val data = ArrayList<Deposit>()

    fun setItems(items: List<Deposit>) {
        data.clear()
        data.addAll(items)
        notifyDataSetChanged()
    }

    fun addItems(items: List<Deposit>) {
        data.addAll(items)
        notifyDataSetChanged()
    }

    fun clear() {
        data.clear()
        notifyDataSetChanged()
    }

    override fun getCount(): Int = data.size

    override fun getItem(position: Int): Deposit = data[position]

    override fun getItemId(position: Int): Long = data[position].depositId

    override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
        val view = convertView ?: inflater.inflate(R.layout.item_deposit, parent, false)
        val deposit = data[position]
        val look = lookFor(deposit.materialClass)

        val material = view.findViewById<TextView>(R.id.depositMaterial)
        material.setText(materialLabel(deposit.materialClass))
        material.setBackgroundResource(look.background)
        material.setCompoundDrawablesWithIntrinsicBounds(look.dot, 0, 0, 0)

        val meta = view.findViewById<TextView>(R.id.depositMeta)
        meta.text = displayTime(deposit.occurredAt)

        val points = view.findViewById<TextView>(R.id.depositPoints)
        if (deposit.points > 0) {
            points.setBackgroundResource(R.drawable.bg_badge_points)
            points.setTextColor(app.getColor(R.color.eco_ink))
            points.setCompoundDrawablesWithIntrinsicBounds(R.drawable.ic_star, 0, 0, 0)
            points.text = app.getString(R.string.student_points_badge, deposit.points)
        } else {
            points.setBackgroundResource(R.drawable.bg_badge_zero)
            points.setTextColor(app.getColor(R.color.eco_slate))
            points.setCompoundDrawablesWithIntrinsicBounds(0, 0, 0, 0)
            points.text = app.getString(R.string.student_zero_points)
        }
        return view
    }

    private data class Look(val background: Int, val dot: Int)

    private fun lookFor(materialClass: String?): Look = when (materialClass) {
        "plastic" -> Look(R.drawable.bg_chip_plastic, R.drawable.ic_dot_plastic)
        "metal" -> Look(R.drawable.bg_chip_metal, R.drawable.ic_dot_metal)
        "glass" -> Look(R.drawable.bg_chip_glass, R.drawable.ic_dot_glass)
        "paper" -> Look(R.drawable.bg_chip_paper, R.drawable.ic_dot_paper)
        else -> Look(R.drawable.bg_chip_other, R.drawable.ic_dot_other)
    }
}
