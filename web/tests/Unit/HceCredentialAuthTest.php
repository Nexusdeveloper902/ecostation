<?php

namespace Tests\Unit;

use App\Services\Hce\HceCredentialAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Known-answer vectors for the per-credential HCE crypto. Every expected
 * value is a LITERAL computed outside the code under test (RFC 4231 and
 * Python's hmac module for the Pulse vector), and the Pulse vector is
 * pinned byte-for-byte in the firmware and Android suites too, so the
 * three implementations cannot drift apart silently.
 */
class HceCredentialAuthTest extends TestCase
{
    private const VECTOR_CRED = 'PLS-K3Y7V3CT0R5Z';

    private const VECTOR_NONCE = '0123456789abcdef';

    private const VECTOR_MAC = 'ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295';

    private const VECTOR_READER_KEY = 'test-secret-000000000000000001';

    private const VECTOR_WRAP_NONCE = '000102030405060708090a0b0c0d0e0f';

    private const VECTOR_WRAPPED = 'c5bb9fe15dff66a8636366dd4e73e0a3146601e3b3c36472961de142a9a914c2';

    private function vectorKey(): string
    {
        return implode('', array_map('chr', range(0, 31)));
    }

    #[Test]
    public function hmac_matches_rfc4231_test_cases_1_and_2(): void
    {
        // TC1: key = 20 x 0x0b, data = "Hi There".
        $this->assertSame(
            'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7',
            bin2hex(HceCredentialAuth::challengeMac(str_repeat("\x0b", 20), 'Hi There', ''))
        );
        // TC2: key = "Jefe", data = "what do ya want for nothing?" (split
        // across the credential/nonce seam on purpose: it is a plain concat).
        $this->assertSame(
            '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843',
            bin2hex(HceCredentialAuth::challengeMac('Jefe', 'what do ya want ', 'for nothing?'))
        );
    }

    #[Test]
    public function the_shared_pulse_challenge_vector_is_pinned(): void
    {
        $this->assertSame(
            self::VECTOR_MAC,
            bin2hex(HceCredentialAuth::challengeMac($this->vectorKey(), self::VECTOR_CRED, hex2bin(self::VECTOR_NONCE)))
        );
    }

    #[Test]
    public function the_shared_key_wrap_vector_is_pinned_and_round_trips(): void
    {
        $wrapped = HceCredentialAuth::wrapKey(self::VECTOR_READER_KEY, self::VECTOR_CRED, self::VECTOR_WRAP_NONCE, $this->vectorKey());
        $this->assertSame(self::VECTOR_WRAPPED, $wrapped);

        // XOR pad: unwrapping is the same operation.
        $this->assertSame(
            bin2hex($this->vectorKey()),
            HceCredentialAuth::wrapKey(self::VECTOR_READER_KEY, self::VECTOR_CRED, self::VECTOR_WRAP_NONCE, hex2bin($wrapped))
        );
        // Uppercase hex nonces derive the same pad (canonical lowercase).
        $this->assertSame(
            $wrapped,
            HceCredentialAuth::wrapKey(self::VECTOR_READER_KEY, self::VECTOR_CRED, strtoupper(self::VECTOR_WRAP_NONCE), $this->vectorKey())
        );
    }

    #[Test]
    public function the_wrap_is_bound_to_reader_credential_and_nonce(): void
    {
        $base = HceCredentialAuth::wrapKey(self::VECTOR_READER_KEY, self::VECTOR_CRED, self::VECTOR_WRAP_NONCE, $this->vectorKey());

        $this->assertNotSame($base, HceCredentialAuth::wrapKey('another-reader-key-0000000000002', self::VECTOR_CRED, self::VECTOR_WRAP_NONCE, $this->vectorKey()));
        $this->assertNotSame($base, HceCredentialAuth::wrapKey(self::VECTOR_READER_KEY, 'PLS-000000000000', self::VECTOR_WRAP_NONCE, $this->vectorKey()));
        $this->assertNotSame($base, HceCredentialAuth::wrapKey(self::VECTOR_READER_KEY, self::VECTOR_CRED, str_repeat('f', 32), $this->vectorKey()));
        // The wrapped value never equals the key itself.
        $this->assertNotSame(bin2hex($this->vectorKey()), $base);
    }

    #[Test]
    public function wrong_key_nonce_or_credential_changes_the_mac(): void
    {
        $key = $this->vectorKey();
        $nonce = hex2bin(self::VECTOR_NONCE);

        $this->assertNotSame(self::VECTOR_MAC, bin2hex(HceCredentialAuth::challengeMac(strrev($key), self::VECTOR_CRED, $nonce)));
        $this->assertNotSame(self::VECTOR_MAC, bin2hex(HceCredentialAuth::challengeMac($key, self::VECTOR_CRED, hex2bin('0123456789abcdee'))));
        $this->assertNotSame(self::VECTOR_MAC, bin2hex(HceCredentialAuth::challengeMac($key, 'PLS-K3Y7V3CT0R5Y', $nonce)));
    }

    #[Test]
    public function the_fingerprint_is_public_and_short(): void
    {
        $this->assertSame('630dcd2966c43366', HceCredentialAuth::fingerprint($this->vectorKey()));
    }
}
