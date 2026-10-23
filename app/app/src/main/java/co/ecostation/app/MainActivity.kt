package co.ecostation.app

import android.app.Activity
import android.os.Bundle
import android.widget.TextView

// Placeholder so the app builds; replaced by the real UI in a later task.
class MainActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(TextView(this).apply { text = getString(R.string.app_name) })
    }
}
