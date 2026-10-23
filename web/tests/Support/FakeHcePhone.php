<?php

namespace Tests\Support;

use App\Models\Reader;
use App\Services\Hce\HceCredentialAuth;

/**
 * Test fixture only — a phone credential plus the reader relay, in PHP.
 * It holds its own random 32-byte key (the Android app keeps it in the
 * Keystore) and produces exactly what the firmware sends: the relayed
 * CHALLENGE proof, and at pairing the reader-wrapped key.
 */
final class FakeHcePhone
{
    public readonly string $key;

    public function __construct(public readonly string $credentialUid, ?string $key = null)
    {
        $this->key = $key ?? random_bytes(HceCredentialAuth::KEY_BYTES);
    }

    /** @return array{hce_nonce: string, hce_mac: string} */
    public function proof(?string $nonce = null, ?string $asCredential = null): array
    {
        $nonce ??= random_bytes(HceCredentialAuth::NONCE_BYTES);

        return [
            'hce_nonce' => bin2hex($nonce),
            'hce_mac' => bin2hex(HceCredentialAuth::challengeMac($this->key, $asCredential ?? $this->credentialUid, $nonce)),
        ];
    }

    /** @return array<string, string> the tap body a reader relays */
    public function tap(): array
    {
        return ['credential_uid' => $this->credentialUid] + $this->proof();
    }

    /** @return array<string, string> the pair body a reader in pairing mode sends */
    public function pair(Reader $reader, ?string $wrapWith = null): array
    {
        $wrapNonce = bin2hex(random_bytes(16));

        return [
            'credential_uid' => $this->credentialUid,
            'credential_kind' => 'hce',
            'hce_key_nonce' => $wrapNonce,
            'hce_key_wrapped' => HceCredentialAuth::wrapKey($wrapWith ?? $reader->api_key, $this->credentialUid, $wrapNonce, $this->key),
        ] + $this->proof();
    }
}
