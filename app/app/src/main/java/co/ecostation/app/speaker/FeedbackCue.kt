package co.ecostation.app.speaker

import org.json.JSONObject

/**
 * The sounds the phone can play and what triggers each. No `rawRes`: the
 * sound assets belong to Task 6, so this mapping stays pure and host-tested.
 *
 *   DETECTED        — this phone's own credential was just read by a reader
 *                     (local HCE path: CHALLENGE answered 9000).
 *   ACCEPTED        — the backend recorded a tap (realtime `tap` frame with
 *                     `event.feedback == "accepted"`), or answered a same-day
 *                     repeat tap that writes no row.
 *   REJECTED        — the backend recorded a flagged tap, an unknown/inactive
 *                     card, or a local CHALLENGE failed.
 *   RECYCLED        — a new classification awarded points (`points > 0`).
 *   NOT_RECYCLABLE  — a new classification awarded none (`points == 0`).
 */
enum class FeedbackCue {
    DETECTED,
    ACCEPTED,
    REJECTED,
    RECYCLED,
    NOT_RECYCLABLE;

    companion object {
        /**
         * One realtime WebSocket text frame -> the cue to play, or null.
         *
         * Only live `tap` and `feedback` frames and the single result frame
         * (`recycling` / `points_awarded` with a `points` field) sound. The
         * `hello` snapshot replays history and must stay silent; the other
         * recycling updates (`capture_created`, `validation_started`,
         * `validated`, `leaderboard_updated`) describe the same deposit and
         * stay silent too, so each deposit gives exactly one result cue.
         */
        fun fromFrame(text: String): FeedbackCue? = try {
            val frame = JSONObject(text)
            when (frame.optString("type")) {
                "feedback" -> cueNamed(frame.getJSONObject("feedback").optString("cue"))
                "tap" -> {
                    val event = frame.getJSONObject("event")
                    cueNamed(event.optString("feedback"))
                        ?: if (event.optBoolean("served", true)) ACCEPTED else REJECTED
                }
                "recycling" -> recycling(frame)
                else -> null
            }
        } catch (e: Exception) {
            null
        }

        /**
         * The result cue comes from `points_awarded` only, and only when the
         * payload actually carries `points`. The web backend emits it once
         * per new classification, including `points == 0` for an `other`
         * result or any material the admin rated 0.
         */
        private fun recycling(frame: JSONObject): FeedbackCue? {
            val update = frame.optJSONObject("update") ?: return null
            if (update.optString("type") != "points_awarded") return null
            val payload = update.optJSONObject("payload") ?: return null
            if (!payload.has("points")) return null
            return if (payload.optInt("points", 0) > 0) RECYCLED else NOT_RECYCLABLE
        }

        private fun cueNamed(name: String): FeedbackCue? = when (name) {
            "accepted" -> ACCEPTED
            "rejected" -> REJECTED
            else -> null
        }

        /** One local HCE exchange line (see EcoHostApduService.describe). */
        fun fromHceEvent(event: String): FeedbackCue? = when (event) {
            "CHALLENGE -> 9000", "ENROLL -> 9000" -> DETECTED
            "CHALLENGE -> error", "CHALLENGE -> no key" -> REJECTED
            else -> null
        }
    }
}
