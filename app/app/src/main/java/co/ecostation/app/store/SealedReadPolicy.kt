package co.ecostation.app.store

import javax.crypto.AEADBadTagException
import javax.crypto.BadPaddingException

/**
 * The pure decision layer for reading a sealed preference.
 *
 * Why it is separate from [AppSettings]: the rule that matters is "a transient
 * Keystore/provider error must KEEP the ciphertext; only a definitive
 * authentication failure may migrate or delete it". That rule has no Android
 * dependency, so it is host-tested without a Context or a real Keystore.
 */
object SealedReadPolicy {

    /** What to do when the field-bound (AAD) open failed. */
    enum class FailureAction {
        /** The ciphertext cannot open under its field AAD: retry legacy/no-AAD. */
        MIGRATE_LEGACY,

        /** The format itself is unrecoverable; drop this one entry. */
        DROP,

        /** The Keystore key is gone: every sealed value is dead with it. */
        INVALIDATE_ALL,

        /** Provider/Keystore hiccup: return null for now and keep the blob. */
        UNAVAILABLE,
    }

    enum class LegacyAction { VALUE, DROP, INVALIDATE_ALL, UNAVAILABLE }

    data class LegacyOutcome(val action: LegacyAction, val value: String? = null)

    fun onBoundFailure(error: Throwable?, keyInvalidated: Boolean): FailureAction = when {
        keyInvalidated -> FailureAction.INVALIDATE_ALL
        isAuthenticationFailure(error) -> FailureAction.MIGRATE_LEGACY
        else -> FailureAction.UNAVAILABLE
    }

    /**
     * Retries an old no-AAD blob and re-seals it. A value that was just read is
     * NEVER deleted, even if re-sealing fails: the legacy blob stays and the
     * next read retries the migration.
     */
    fun migrateLegacy(
        openLegacy: () -> String?,
        reseal: (String) -> Unit,
        isInvalidated: (Throwable?) -> Boolean,
    ): LegacyOutcome {
        val legacy = try {
            openLegacy()
        } catch (e: Throwable) {
            return LegacyOutcome(
                when {
                    isInvalidated(e) -> LegacyAction.INVALIDATE_ALL
                    // Both the field-AAD and the legacy read failed
                    // authentication: a swapped/corrupt blob, not a transient.
                    isAuthenticationFailure(e) -> LegacyAction.DROP
                    else -> LegacyAction.UNAVAILABLE
                },
            )
        }
        if (legacy == null) return LegacyOutcome(LegacyAction.DROP)
        return try {
            reseal(legacy)
            LegacyOutcome(LegacyAction.VALUE, legacy)
        } catch (e: Throwable) {
            // Keep both the value and the legacy blob; never delete a secret
            // that was just successfully read.
            LegacyOutcome(LegacyAction.VALUE, legacy)
        }
    }

    /** True for GCM/LegacyPad authentication failures anywhere in the cause chain. */
    fun isAuthenticationFailure(error: Throwable?): Boolean {
        var current = error
        var depth = 0
        while (current != null && depth < 8) {
            if (current is AEADBadTagException || current is BadPaddingException) return true
            current = current.cause
            depth++
        }
        return false
    }
}
