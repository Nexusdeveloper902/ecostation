package co.ecostation.app.speaker

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioDeviceInfo
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioTrack
import android.media.SoundPool
import android.os.Build
import co.ecostation.app.R

/**
 * Reproduce los cinco sonidos por la salida multimedia que Android esté
 * usando: un altavoz Bluetooth A2DP cuando hay uno, el altavoz del teléfono
 * si no. Android hace el enrutado; la app solo pide USAGE_MEDIA, que (a
 * diferencia de sonification) sigue al A2DP y no lo silencia el modo
 * silencio del timbre.
 *
 * Keep-alive de A2DP: los altavoces Bluetooth suspenden el flujo tras unos
 * segundos de silencio y reanudarlo se come los primeros 100–300 ms, es decir
 * casi todo un pitido de 190 ms. Mientras la pantalla está arriba se repite un
 * segundo de silencio digital en el flujo multimedia para mantener el enlace
 * caliente. No se pide foco de audio; las demás apps no se ven afectadas.
 */
class FeedbackSounds(context: Context) {

    private val app = context.applicationContext
    private val audio = app.getSystemService(Context.AUDIO_SERVICE) as AudioManager
    private val attributes = AudioAttributes.Builder()
        .setUsage(AudioAttributes.USAGE_MEDIA)
        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
        .build()

    private val pool = SoundPool.Builder().setMaxStreams(4).setAudioAttributes(attributes).build()
    private val ids = FeedbackCue.entries.associateWith { pool.load(app, rawFor(it), 1) }
    private val loaded = HashSet<Int>()
    private var keepAlive: AudioTrack? = null

    /** Set by [release]; a late callback must never touch a released pool. */
    @Volatile private var released = false

    init {
        pool.setOnLoadCompleteListener { _, id, status -> if (status == 0) synchronized(loaded) { loaded.add(id) } }
    }

    fun play(cue: FeedbackCue) {
        if (released) return
        val id = ids.getValue(cue)
        if (synchronized(loaded) { id !in loaded }) return
        try {
            pool.play(id, 1f, 1f, 1, 0, 1f)
        } catch (_: Exception) {
            // El pool se liberó entre la comprobación y la llamada: no suena.
        }
    }

    fun startKeepAlive() {
        if (released || keepAlive != null) return
        keepAlive = try {
            val rate = 44_100
            val silence = ShortArray(rate) // 1 s de ceros
            AudioTrack(
                attributes,
                AudioFormat.Builder()
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                    .setSampleRate(rate)
                    .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                    .build(),
                silence.size * 2,
                AudioTrack.MODE_STATIC,
                AudioManager.AUDIO_SESSION_ID_GENERATE,
            ).apply {
                write(silence, 0, silence.size)
                setLoopPoints(0, silence.size, -1)
                play()
            }
        } catch (e: Exception) {
            null // el keep-alive es una optimización; sin él los pitidos igual suenan
        }
    }

    fun stopKeepAlive() {
        keepAlive?.let {
            try {
                it.stop()
            } catch (_: Exception) {
            }
            it.release()
        }
        keepAlive = null
    }

    /** "Bluetooth: JBL Charge 5 · vol 8/15" o "altavoz del teléfono …" — solo diagnóstico. */
    fun outputDescription(): String {
        val outputs = audio.getDevices(AudioManager.GET_DEVICES_OUTPUTS)
        val bt = outputs.firstOrNull { it.type == AudioDeviceInfo.TYPE_BLUETOOTH_A2DP }
            ?: outputs.firstOrNull { Build.VERSION.SDK_INT >= 31 && it.type == AudioDeviceInfo.TYPE_BLE_SPEAKER }
        val volume = "${audio.getStreamVolume(AudioManager.STREAM_MUSIC)}/${audio.getStreamMaxVolume(AudioManager.STREAM_MUSIC)}"
        return if (bt != null) {
            "Bluetooth: ${bt.productName} · vol $volume"
        } else {
            "altavoz del teléfono (sin Bluetooth A2DP) · vol $volume"
        }
    }

    fun release() {
        if (released) return
        released = true
        stopKeepAlive()
        try {
            pool.release()
        } catch (_: Exception) {
        }
    }

    private fun rawFor(cue: FeedbackCue): Int = when (cue) {
        FeedbackCue.DETECTED -> R.raw.eco_beep_detected
        FeedbackCue.ACCEPTED -> R.raw.eco_beep_accepted
        FeedbackCue.REJECTED -> R.raw.eco_beep_rejected
        FeedbackCue.RECYCLED -> R.raw.eco_cue_recycled
        FeedbackCue.NOT_RECYCLABLE -> R.raw.eco_cue_not_recyclable
    }
}
