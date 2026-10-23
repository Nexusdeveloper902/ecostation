package co.ecostation.app.hce

import org.junit.Assert.*
import org.junit.Test
import co.ecostation.app.BuildConfig

/**
 * The credential APDU protocol (HCE_PROTOCOL.md v1.1), pinned with LITERAL bytes.
 *
 * Every expected MAC below was computed outside this code (RFC 4231, and
 * Python's hmac module for the shared vector) — never by the implementation
 * under test. The shared vector is pinned identically in the backend and
 * the reader firmware; its `PLS-` id is a wire literal, not our prefix.
 * Run with `./gradlew :app:testDebugUnitTest`.
 */
class ApduProtocolTest {

    private fun hex(s: String): ByteArray = s.chunked(2).map { it.toInt(16).toByte() }.toByteArray()

    private val vectorKey = ByteArray(32) { it.toByte() }

    private fun keysWithVector() = InMemoryCredentialKeyStore().apply { put(VECTOR_CRED, vectorKey) }

    private fun select(aid: ByteArray = ApduProtocol.AID): ByteArray =
        byteArrayOf(0x00, 0xA4.toByte(), 0x04, 0x00, aid.size.toByte()) + aid

    private fun challenge(nonce: ByteArray = ByteArray(8) { it.toByte() }): ByteArray =
        byteArrayOf(0x80.toByte(), 0x10, 0x00, 0x00, 0x08) + nonce

    private val enroll = byteArrayOf(0x80.toByte(), 0x20, 0x00, 0x00, 0x20)

    private fun run(
        cmd: ByteArray,
        s: ApduProtocol.Session,
        keys: CredentialKeyStore = keysWithVector(),
        window: EnrollmentWindow = EnrollmentWindow(),
        cred: String = VECTOR_CRED,
        now: Long = 0,
    ) = ApduProtocol.process(cmd, s, cred, keys, window, now)

    // ------------------------------------------------------------------ config

    @Test
    fun routing_report_wording_is_pinned() {
        assertEquals(
            "ROUTED (default for F0010203040506)",
            ApduProtocol.routingReport(true, listOf("F0010203040506"))
        )
        assertTrue(ApduProtocol.routingReport(false, listOf("F0010203040506")).startsWith("NOT ROUTED"))
        assertTrue(ApduProtocol.routingReport(null, null).startsWith("UNKNOWN"))
        // Empty registry is still a verdict, not UNKNOWN.
        assertTrue(ApduProtocol.routingReport(true, emptyList()).startsWith("ROUTED"))
    }

    @Test
    fun buildConfig_carries_the_aid_a_blank_id_and_no_hce_secret() {
        // A silent AID drift breaks every tap with no protocol change.
        assertArrayEquals(
            byteArrayOf(0xF0.toByte(), 0x01, 0x02, 0x03, 0x04, 0x05, 0x06),
            ApduProtocol.AID
        )
        assertEquals("F0010203040506", BuildConfig.ECO_AID_HEX)
        // BLANK so CredentialId derives a per-device identity.
        assertEquals("", BuildConfig.ECO_CRED_ID)
        // No key material, credential or account in the build: any field
        // whose name or value looks like one is a regression.
        val banned = Regex("(?i)(secret|key|password|token|email)")
        val hex64 = Regex("^[0-9a-fA-F]{64}$")
        for (f in BuildConfig::class.java.declaredFields) {
            assertFalse(f.name, banned.containsMatchIn(f.name))
            if (f.type == String::class.java) {
                f.isAccessible = true
                assertFalse(f.name, hex64.matches(f.get(null) as String))
            }
        }
    }

    // -------------------------------------------------------------- identity

    @Test
    fun credentialId_is_stable_unique_and_wire_safe() {
        val a = CredentialId.derive("androidid-aaaaaaaa")
        val b = CredentialId.derive("androidid-bbbbbbbb")

        assertEquals(a, CredentialId.derive("androidid-aaaaaaaa"))
        assertNotEquals(a, b)

        // 16 ASCII chars keeps the CHALLENGE response at 51 bytes of INF.
        assertEquals(16, a.length)
        assertTrue(a.all { it.code in 0x20..0x7E })
        assertTrue(a.startsWith("ECO-"))
        assertTrue(CredentialId.isWellFormed(a))
        assertTrue(a.drop(4).none { it == 'I' || it == 'L' || it == 'O' || it == 'U' })

        // Domain separation: the raw device identifier never reaches the wire.
        assertNotEquals("androidid-aaaaaaaa", a)
        assertFalse(a.contains("androidid-aaaaaaaa"))
        assertFalse(a.contains("androidid"))
    }

    @Test
    fun credentialId_rejects_malformed_ids() {
        assertFalse(CredentialId.isWellFormed(null))
        assertFalse(CredentialId.isWellFormed(""))
        assertFalse(CredentialId.isWellFormed("TEST-ANDROID-001"))
        assertFalse(CredentialId.isWellFormed("ECO-TOOSHORT"))
        assertFalse(CredentialId.isWellFormed("ECO-IIIIIIIIIIII"))
        assertTrue(CredentialId.isWellFormed("ECO-K3Y7V3CT0R5Z"))
        // The old product prefix is not ours (the vector id is a wire literal only).
        assertFalse(CredentialId.isWellFormed("PLS-K3Y7V3CT0R5Z"))
    }

    // ----------------------------------------------------- known-answer HMAC

    @Test
    fun hmac_matches_rfc4231_test_cases_through_the_key_store_contract() {
        val keys = InMemoryCredentialKeyStore()
        // TC1: key = 20 x 0x0b, data = "Hi There".
        keys.put("tc1", ByteArray(20) { 0x0b })
        assertArrayEquals(
            hex("b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7"),
            keys.mac("tc1", "Hi There".toByteArray(Charsets.US_ASCII))
        )
        // TC2: key = "Jefe", data = "what do ya want for nothing?".
        keys.put("tc2", "Jefe".toByteArray(Charsets.US_ASCII))
        assertArrayEquals(
            hex("5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843"),
            keys.mac("tc2", "what do ya want for nothing?".toByteArray(Charsets.US_ASCII))
        )
    }

    @Test
    fun challenge_answer_matches_the_shared_pulse_vector_byte_for_byte() {
        val s = ApduProtocol.Session()
        assertArrayEquals("HCE-OK".toByteArray() + ApduProtocol.SW_OK, run(select(), s))
        assertTrue(s.selected)

        val resp = run(challenge(hex(VECTOR_NONCE)), s)
        assertArrayEquals(hex(VECTOR_RESPONSE), resp)
        assertEquals(51, resp.size) // 1 + 16 + 32 + 2 — unchanged wire size
    }

    @Test
    fun the_message_layout_is_credential_id_then_nonce() {
        assertArrayEquals(
            "PLS-K3Y7V3CT0R5Z".toByteArray(Charsets.US_ASCII) + hex(VECTOR_NONCE),
            ApduProtocol.challengeMessage(VECTOR_CRED, hex(VECTOR_NONCE))
        )
    }

    // --------------------------------------------------------- key isolation

    @Test
    fun a_different_key_nonce_or_credential_gives_a_different_answer() {
        val vectorMac = hex(VECTOR_RESPONSE).sliceArray(17 until 49)

        // Wrong key (another phone claiming the same id).
        val otherKeys = InMemoryCredentialKeyStore().apply { put(VECTOR_CRED, ByteArray(32) { 0x55 }) }
        val s1 = ApduProtocol.Session().also { run(select(), it, otherKeys) }
        assertFalse(run(challenge(hex(VECTOR_NONCE)), s1, otherKeys).sliceArray(17 until 49).contentEquals(vectorMac))

        // Wrong (fresh) nonce: the answer changes with every challenge.
        val s2 = ApduProtocol.Session().also { run(select(), it) }
        val fresh = run(challenge(hex("0123456789abcdee")), s2)
        assertFalse(fresh.sliceArray(17 until 49).contentEquals(vectorMac))

        // Same key, other credential id: credential A's key yields a MAC that
        // B2B-Core rejects for B, because the id is inside the MAC.
        val shared = InMemoryCredentialKeyStore().apply { put("PLS-K3Y7V3CT0R5Y", vectorKey) }
        val s3 = ApduProtocol.Session().also { run(select(), it, shared, cred = "PLS-K3Y7V3CT0R5Y") }
        val asB = run(challenge(hex(VECTOR_NONCE)), s3, shared, cred = "PLS-K3Y7V3CT0R5Y")
        assertFalse(asB.sliceArray(17 until 49).contentEquals(vectorMac))
    }

    @Test
    fun a_credential_never_uses_another_credentials_key() {
        // Only B has a key; asking as A must not fall back to it.
        val keys = InMemoryCredentialKeyStore().apply { put("ECO-BBBBBBBBBBBB", vectorKey) }
        val s = ApduProtocol.Session().also { run(select(), it, keys, cred = "ECO-AAAAAAAAAAAA") }
        assertArrayEquals(ApduProtocol.SW_KEY_MISSING, run(challenge(), s, keys, cred = "ECO-AAAAAAAAAAAA"))
    }

    @Test
    fun missing_or_corrupt_key_answers_6A88_never_a_mac() {
        val empty = InMemoryCredentialKeyStore()
        val s = ApduProtocol.Session().also { run(select(), it, empty) }
        assertArrayEquals(byteArrayOf(0x6A, 0x88.toByte()), run(challenge(), s, empty))

        val corrupt = keysWithVector().apply { corrupt(VECTOR_CRED) }
        val s2 = ApduProtocol.Session().also { run(select(), it, corrupt) }
        assertArrayEquals(ApduProtocol.SW_KEY_MISSING, run(challenge(), s2, corrupt))
    }

    // ------------------------------------------------------------- malformed

    @Test
    fun rejects_unknownAid_challengeFirst_malformed() {
        val s = ApduProtocol.Session()
        // Unknown AID (valid length, wrong value) -> 6A82, session stays unselected.
        val bad = run(select(byteArrayOf(0x00, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66)), s)
        assertArrayEquals(byteArrayOf(0x6A.toByte(), 0x82.toByte()), bad)
        assertFalse(s.selected)
        // CHALLENGE and ENROLL before SELECT -> 6985.
        assertArrayEquals(byteArrayOf(0x69.toByte(), 0x85.toByte()), run(challenge(), s))
        assertArrayEquals(byteArrayOf(0x69.toByte(), 0x85.toByte()), run(enroll, s))
        // Truncated / unknown CLA+INS.
        assertArrayEquals(byteArrayOf(0x67.toByte(), 0x00), run(byteArrayOf(0x00, 0xA4.toByte()), ApduProtocol.Session()))
        assertArrayEquals(byteArrayOf(0x6E.toByte(), 0x00), run(byteArrayOf(0xFF.toByte(), 0x00, 0x00, 0x00), ApduProtocol.Session()))
        assertArrayEquals(byteArrayOf(0x6D.toByte(), 0x00), run(byteArrayOf(0x00, 0xFF.toByte(), 0x00, 0x00), ApduProtocol.Session()))
        assertArrayEquals(byteArrayOf(0x6D.toByte(), 0x00), run(byteArrayOf(0x80.toByte(), 0x30, 0x00, 0x00), ApduProtocol.Session()))
        assertArrayEquals(byteArrayOf(0x67.toByte(), 0x00), run(ByteArray(0), ApduProtocol.Session()))
    }

    @Test
    fun wrong_lengths_are_6700() {
        val s = ApduProtocol.Session().also { run(select(), it) }
        val sw6700 = byteArrayOf(0x67, 0x00)
        // SELECT: Lc shorter/longer than the AID, or data truncated.
        assertArrayEquals(sw6700, run(byteArrayOf(0x00, 0xA4.toByte(), 0x04, 0x00, 0x06) + ApduProtocol.AID.copyOf(6), ApduProtocol.Session()))
        assertArrayEquals(sw6700, run(byteArrayOf(0x00, 0xA4.toByte(), 0x04, 0x00, 0x07, 0xF0.toByte()), ApduProtocol.Session()))
        // CHALLENGE: header only, Lc != 8, nonce truncated.
        assertArrayEquals(sw6700, run(byteArrayOf(0x80.toByte(), 0x10, 0x00, 0x00), s))
        assertArrayEquals(sw6700, run(byteArrayOf(0x80.toByte(), 0x10, 0x00, 0x00, 0x07) + ByteArray(7), s))
        assertArrayEquals(sw6700, run(byteArrayOf(0x80.toByte(), 0x10, 0x00, 0x00, 0x08) + ByteArray(5), s))
        // ENROLL must not carry a data field.
        assertArrayEquals(sw6700, run(byteArrayOf(0x80.toByte(), 0x20, 0x00, 0x00, 0x01, 0x00), s))
        // A trailing Le on CHALLENGE is accepted and ignored.
        val withLe = run(challenge(hex(VECTOR_NONCE)) + byteArrayOf(0x00), s)
        assertArrayEquals(hex(VECTOR_RESPONSE), withLe)
    }

    // ---------------------------------------------------------------- enroll

    @Test
    fun enroll_releases_the_key_only_inside_an_open_window_and_only_once() {
        val window = EnrollmentWindow(ttlMs = 60_000)
        val s = ApduProtocol.Session().also { run(select(), it) }

        // Closed window: nothing to hand out.
        assertArrayEquals(byteArrayOf(0x69.toByte(), 0x85.toByte()), run(enroll, s, window = window))

        window.open(VECTOR_CRED, vectorKey, nowMs = 1_000)
        val resp = run(enroll, s, window = window, now = 2_000)
        assertArrayEquals(vectorKey + ApduProtocol.SW_OK, resp)
        assertEquals(34, resp.size) // fits one RC522 I-block

        // Released once: a second reader (or a skimmer) gets nothing.
        assertArrayEquals(byteArrayOf(0x69.toByte(), 0x85.toByte()), run(enroll, s, window = window, now = 2_001))

        // Expired window: nothing either.
        window.open(VECTOR_CRED, vectorKey, nowMs = 0)
        assertArrayEquals(byteArrayOf(0x69.toByte(), 0x85.toByte()), run(enroll, s, window = window, now = 60_000))

        // Header-only ENROLL (no Le) is accepted too.
        window.open(VECTOR_CRED, vectorKey, nowMs = 0)
        assertArrayEquals(vectorKey + ApduProtocol.SW_OK, run(enroll.copyOf(4), s, window = window, now = 1))
    }

    @Test
    fun enroll_never_hands_a_key_to_another_credential_id() {
        val window = EnrollmentWindow().apply { open("ECO-AAAAAAAAAAAA", vectorKey, 0) }
        val s = ApduProtocol.Session().also { run(select(), it, cred = "ECO-BBBBBBBBBBBB") }
        assertArrayEquals(
            byteArrayOf(0x69.toByte(), 0x85.toByte()),
            run(enroll, s, window = window, cred = "ECO-BBBBBBBBBBBB", now = 1)
        )
    }

    companion object {
        // Shared per-credential vector (HCE_PROTOCOL.md v1.1). Byte-identical: never rename.
        const val VECTOR_CRED = "PLS-K3Y7V3CT0R5Z"
        const val VECTOR_NONCE = "0123456789abcdef"
        const val VECTOR_RESPONSE =
            "10504c532d4b335937563343543052355a" +
                "ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295" +
                "9000"
    }
}
