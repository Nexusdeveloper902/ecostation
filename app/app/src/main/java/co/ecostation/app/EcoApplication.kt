package co.ecostation.app

import android.app.Application
import android.util.Log
import java.io.File

/**
 * Last-resort crash breadcrumb: write the stack trace to
 * `filesDir/last-crash.txt` before the process dies. This is a debug aid for
 * field devices where `adb logcat` is not available; it never hides the crash
 * (the default handler still runs).
 */
class EcoApplication : Application() {

    override fun onCreate() {
        super.onCreate()
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                File(filesDir, "last-crash.txt").writeText(
                    buildString {
                        appendLine("thread=${thread.name}")
                        appendLine(Log.getStackTraceString(throwable))
                    },
                )
            } catch (_: Exception) {
                // Never mask the original crash with a logging failure.
            }
            previous?.uncaughtException(thread, throwable)
        }
    }
}
