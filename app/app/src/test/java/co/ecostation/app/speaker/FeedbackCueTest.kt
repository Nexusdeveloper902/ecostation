package co.ecostation.app.speaker

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Frame → cue mapping. Pure: no Android, no sound. */
class FeedbackCueTest {

    @Test
    fun onlyLiveTapFramesSound() {
        assertEquals(FeedbackCue.ACCEPTED, FeedbackCue.fromFrame("""{"type":"tap","event":{"id":1,"served":true,"feedback":"accepted"}}"""))
        assertEquals(FeedbackCue.REJECTED, FeedbackCue.fromFrame("""{"type":"tap","event":{"id":2,"served":false,"reason":"weekend","feedback":"rejected"}}"""))
        // Backend without the feedback field: derived from served.
        assertEquals(FeedbackCue.REJECTED, FeedbackCue.fromFrame("""{"type":"tap","event":{"id":3,"served":false}}"""))
        assertEquals(FeedbackCue.ACCEPTED, FeedbackCue.fromFrame("""{"type":"tap","event":{"id":4}}"""))
        // Answered taps that wrote no events row (repeat / unknown card).
        assertEquals(FeedbackCue.ACCEPTED, FeedbackCue.fromFrame("""{"type":"feedback","feedback":{"id":7,"cue":"accepted","reason":"duplicate","event_id":41}}"""))
        assertEquals(FeedbackCue.REJECTED, FeedbackCue.fromFrame("""{"type":"feedback","feedback":{"id":8,"cue":"rejected","reason":"not_found","event_id":null}}"""))
        assertNull(FeedbackCue.fromFrame("""{"type":"feedback","feedback":{"cue":"weird"}}"""))
        // History replay, other channels and garbage stay silent.
        assertNull(FeedbackCue.fromFrame("""{"type":"hello","last_id":9,"events":[{"feedback":"accepted"}]}"""))
        assertNull(FeedbackCue.fromFrame("""{"type":"pairing"}"""))
        assertNull(FeedbackCue.fromFrame("not json"))
        assertNull(FeedbackCue.fromFrame("""{"type":"tap"}"""))
    }

    @Test
    fun recycling_frames_give_one_result_cue_per_deposit() {
        fun upd(type: String, payload: String) =
            """{"type":"recycling","update":{"id":1,"type":"$type","payload":$payload}}"""

        assertEquals(
            FeedbackCue.RECYCLED,
            FeedbackCue.fromFrame(upd("points_awarded", """{"points":10,"material_class":"plastic","new_balance":40}""")),
        )
        assertEquals(
            FeedbackCue.NOT_RECYCLABLE,
            FeedbackCue.fromFrame(upd("points_awarded", """{"points":0,"material_class":"other","new_balance":30}""")),
        )
        // No points field: silent.
        assertNull(FeedbackCue.fromFrame(upd("points_awarded", """{"material_class":"plastic"}""")))
        // Every other update of the same deposit is silent: one cue in total.
        for (t in listOf("capture_created", "validation_started", "validated", "leaderboard_updated")) {
            assertNull(t, FeedbackCue.fromFrame(upd(t, """{"points":10,"is_recyclable":true}""")))
        }
        assertNull(FeedbackCue.fromFrame("""{"type":"recycling","update":{}}"""))
        assertNull(FeedbackCue.fromFrame("""{"type":"hello","recycling":[{"type":"points_awarded","payload":{"points":10}}]}"""))
    }

    @Test
    fun localHceExchangeMapsToDetectedOrRejected() {
        assertEquals(FeedbackCue.DETECTED, FeedbackCue.fromHceEvent("RETO -> 9000"))
        assertEquals(FeedbackCue.DETECTED, FeedbackCue.fromHceEvent("ENROLAR -> 9000"))
        assertEquals(FeedbackCue.REJECTED, FeedbackCue.fromHceEvent("RETO -> error"))
        assertEquals(FeedbackCue.REJECTED, FeedbackCue.fromHceEvent("RETO -> sin llave"))
        assertNull(FeedbackCue.fromHceEvent("ENROLAR -> error"))
        assertNull(FeedbackCue.fromHceEvent("SELECCIONAR AID -> 9000"))
        assertNull(FeedbackCue.fromHceEvent("Desactivado"))
    }
}
