package co.ecostation.app.store

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.io.IOException
import javax.crypto.spec.SecretKeySpec

/**
 * The migration decision: a transient Keystore/provider error must KEEP the
 * ciphertext, a successful legacy read must keep its value even if re-sealing
 * fails, and a swapped AAD is a definitive drop.
 */
class SealedReadPolicyTest {

    private val key = SecretKeySpec(ByteArray(32) { it.toByte() }, "AES")
    private val student = "student_token".toByteArray()
    private val speaker = "speaker_password".toByteArray()

    @Test
    fun transient_errors_are_unavailable_and_never_migrate() {
        assertEquals(
            SealedReadPolicy.FailureAction.UNAVAILABLE,
            SealedReadPolicy.onBoundFailure(IOException("Keystore busy"), keyInvalidated = false),
        )
        assertEquals(
            SealedReadPolicy.FailureAction.INVALIDATE_ALL,
            SealedReadPolicy.onBoundFailure(IOException("gone"), keyInvalidated = true),
        )
        var opened = false
        val outcome = SealedReadPolicy.migrateLegacy(
            openLegacy = { opened = true; throw IOException("proveedor no disponible") },
            reseal = { fail("must not re-seal when the legacy read failed") },
            isInvalidated = { false },
        )
        assertTrue(opened)
        assertEquals(SealedReadPolicy.LegacyAction.UNAVAILABLE, outcome.action)
        // UNAVAILABLE is the caller's signal to keep the blob untouched.
        assertNull(outcome.value)
    }

    @Test
    fun authentication_failure_migrates_but_a_swapped_aad_is_dropped() {
        val legacy = Sealer.seal(key, "5|abc", null)
        // The field-bound read authenticates under the wrong AAD: definite
        // authentication failure, so the legacy retry is allowed.
        try {
            Sealer.openStrict(key, legacy, student)
            fail("legacy blob must not open under a field AAD")
        } catch (e: Exception) {
            assertEquals(
                SealedReadPolicy.FailureAction.MIGRATE_LEGACY,
                SealedReadPolicy.onBoundFailure(e, keyInvalidated = false),
            )
        }

        // A blob sealed for another field fails BOTH the field AAD and the
        // legacy no-AAD read: dropped, not deleted on a transient.
        val swapped = Sealer.seal(key, "clave", speaker)
        val outcome = try {
            SealedReadPolicy.migrateLegacy(
                openLegacy = { Sealer.openStrict(key, swapped, null) },
                reseal = { fail("a swapped AAD must never reach re-seal") },
                isInvalidated = { false },
            )
        } catch (e: Exception) {
            fail("the policy must classify authentication failure, not throw: $e")
            error("unreachable")
        }
        assertEquals(SealedReadPolicy.LegacyAction.DROP, outcome.action)
        assertNull(outcome.value)
    }

    @Test
    fun a_successful_legacy_read_returns_the_value_even_if_resealing_fails() {
        var resealed = false
        val outcome = SealedReadPolicy.migrateLegacy(
            openLegacy = { "5|abc" },
            reseal = {
                resealed = true
                throw IOException("Keystore no disponible al re-sellar")
            },
            isInvalidated = { false },
        )
        assertTrue(resealed)
        assertEquals(SealedReadPolicy.LegacyAction.VALUE, outcome.action)
        // The secret was read: the value survives and the caller keeps the
        // legacy blob because the reseal never replaced it.
        assertEquals("5|abc", outcome.value)
        assertFalse(outcome.action == SealedReadPolicy.LegacyAction.DROP)
    }

    @Test
    fun a_successful_reseal_returns_the_value() {
        var stored: String? = null
        val outcome = SealedReadPolicy.migrateLegacy(
            openLegacy = { "5|abc" },
            reseal = { stored = Sealer.seal(key, it, student) },
            isInvalidated = { false },
        )
        assertEquals(SealedReadPolicy.LegacyAction.VALUE, outcome.action)
        assertEquals("5|abc", outcome.value)
        assertEquals("5|abc", Sealer.openStrict(key, stored, student))
    }

    @Test
    fun an_invalidated_key_during_the_legacy_read_invalidates_all() {
        val outcome = SealedReadPolicy.migrateLegacy(
            openLegacy = { throw IllegalArgumentException("llave muerta") },
            reseal = { fail("must not reseal") },
            isInvalidated = { it is IllegalArgumentException },
        )
        assertEquals(SealedReadPolicy.LegacyAction.INVALIDATE_ALL, outcome.action)
    }
}
