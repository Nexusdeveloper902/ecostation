<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\HceCredentialKey;
use App\Models\PendingPairing;
use App\Models\Reader;
use App\Models\Student;
use App\Services\Hce\HceCredentialAuth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Two-step card pairing (arm-then-pair): arming happens on the dashboard
 * side, pairing happens on the device side, and the short window is the
 * synchronization between the two.
 */
class PairingService
{
    public function __construct(
        private readonly ?int $windowSeconds = null,
    ) {}

    /** Resolved per call so an admin settings change is live immediately. */
    private function window(): int
    {
        return $this->windowSeconds ?? settings()->pairingWindowSeconds();
    }

    /**
     * Arm a pending pairing for a student: the next fresh card scanned
     * within the window will be linked to them. One armed window at a
     * time — arming closes every other still-active window first.
     */
    public function arm(Student $student): PendingPairing
    {
        return DB::transaction(function () use ($student) {
            PendingPairing::active()
                ->update(['expires_at' => now()]);

            return PendingPairing::create([
                'student_id' => $student->id,
                'expires_at' => now()->addSeconds($this->window()),
            ]);
        });
    }

    /** The active pending pairing (the one the next fresh scan would consume), or null. */
    public function activeSession(): ?PendingPairing
    {
        return PendingPairing::active()
            ->orderByDesc('id')
            ->with('student')
            ->first();
    }

    /**
     * Completed pairings (consumed + card stamped), newest first.
     *
     * @return Collection<int, PendingPairing>
     */
    public function recentCompletions(int $limit = 8): Collection
    {
        return PendingPairing::whereNotNull('consumed_at')
            ->whereNotNull('card_id')
            ->with(['student', 'card', 'reader'])
            ->orderByDesc('consumed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The pairing status payload built from one place: the REST status
     * endpoint and the realtime pairing frames serialize this exact array.
     *
     * @return array{pending: array<string, mixed>|null, last_pairing: array<string, mixed>|null, recent_pairings: array<int, array<string, mixed>>}
     */
    public function statusPayload(): array
    {
        $active = $this->activeSession();
        $recent = $this->recentCompletions(8);

        return [
            'pending' => $active !== null ? [
                'student_id' => $active->student_id,
                'student_name' => $active->student?->name,
                'expires_at' => $active->expires_at->toIso8601String(),
                'seconds_left' => max(0, (int) now()->diffInSeconds($active->expires_at)),
                // The latest REJECTED tap on this armed window (422
                // already_paired) shows at the desk instead of a silent countdown.
                'last_rejection' => $active->last_rejected_uid !== null ? [
                    'card_uid' => $active->last_rejected_uid,
                    'reason' => $active->last_rejected_reason,
                    'at' => $active->last_rejected_at?->toIso8601String(),
                ] : null,
            ] : null,
            'last_pairing' => $recent->isNotEmpty() ? [
                'card_uid' => $recent->first()->card?->credential_uid,
                'card_kind' => $recent->first()->card?->kind?->value ?? 'physical',
                'student_id' => $recent->first()->student_id,
                'student_name' => $recent->first()->student?->name,
                'paired_at' => $recent->first()->consumed_at?->toIso8601String(),
                'reader_label' => $recent->first()->reader?->label,
            ] : null,
            'recent_pairings' => $recent->map(fn ($p) => [
                'card_uid' => $p->card?->credential_uid,
                'card_kind' => $p->card?->kind?->value ?? 'physical',
                'student_id' => $p->student_id,
                'student_name' => $p->student?->name,
                'paired_at' => $p->consumed_at?->toIso8601String(),
                'reader_label' => $p->reader?->label,
            ])->values()->all(),
        ];
    }

    /**
     * Pair a scanned credential with the most recent active pending pairing.
     *
     * `$kind` is display/audit metadata only: the tap lookup stays
     * credential_uid-only, so old readers that omit it pair as before.
     * An `hce` pairing is also the key hand-off: the key is accepted only
     * if the proof verifies under it, and an existing phone credential can
     * be re-keyed only for the same student this window was armed for.
     * A `revoked` credential (unpair) is deliberately reactivatable for
     * the armed student; an ACTIVE one owned by someone else is not.
     *
     * @param  array<string, mixed>  $hce
     * @return array{ok: true, card: Card, student: Student, pairing: PendingPairing, rekeyed: bool}|array{ok: false, reason: 'no_session'|'already_paired'|'hce_proof_invalid'}
     */
    public function pair(Reader $reader, string $credentialUid, string $kind = 'physical', array $hce = []): array
    {
        $kind = $kind === 'hce' ? 'hce' : 'physical';

        return DB::transaction(function () use ($reader, $credentialUid, $kind, $hce) {
            // Lock the candidate row so two simultaneous pair taps cannot
            // both consume the same pending session.
            /** @var PendingPairing|null $pairing */
            $pairing = PendingPairing::active()
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($pairing === null) {
                return ['ok' => false, 'reason' => 'no_session'];
            }

            // Never silently reassign an existing card to a new student.
            $existing = Card::where('credential_uid', $credentialUid)
                ->lockForUpdate()
                ->first();

            $key = null;
            if ($kind === 'hce') {
                $accepted = app(HceCredentialAuth::class)->acceptProvisioning($reader, $credentialUid, $hce);

                if (! $accepted['ok']) {
                    $pairing->update([
                        'last_rejected_uid' => $credentialUid,
                        'last_rejected_reason' => 'hce_proof_invalid',
                        'last_rejected_at' => now(),
                    ]);

                    return ['ok' => false, 'reason' => 'hce_proof_invalid'];
                }

                $key = $accepted['key'];

                $rekey = $existing !== null
                    && $existing->isHce()
                    && $existing->isActive()
                    && (int) $existing->student_id === (int) $pairing->student_id;

                if ($rekey) {
                    app(HceCredentialAuth::class)->storeKey($existing, $key, $reader);

                    return $this->consume($pairing, $reader, $existing, rekeyed: true);
                }
            }

            // A revoked card belongs to nobody in operation: an armed
            // window may deliberately reactivate that same credential for
            // the student at the desk. The row (and therefore its history)
            // is reused; the phone gets a fresh key. An ACTIVE card of
            // another student is still never reassigned.
            if ($existing !== null && $existing->status === CardStatus::Revoked) {
                $existing->update([
                    'student_id' => $pairing->student_id,
                    'status' => CardStatus::Active,
                    'kind' => $kind,
                ]);

                if ($key !== null) {
                    app(HceCredentialAuth::class)->storeKey($existing, $key, $reader);
                } else {
                    // Physical re-pair: a stale key from a previous phone
                    // shape must not survive.
                    HceCredentialKey::where('card_id', $existing->id)->delete();
                }

                return $this->consume($pairing, $reader, $existing, rekeyed: false);
            }

            if ($existing !== null) {
                // The rejection must be visible at the desk: stamp it on the
                // armed window that witnessed it.
                $pairing->update([
                    'last_rejected_uid' => $credentialUid,
                    'last_rejected_reason' => 'already_paired',
                    'last_rejected_at' => now(),
                ]);

                return ['ok' => false, 'reason' => 'already_paired'];
            }

            $card = Card::create([
                'credential_uid' => $credentialUid,
                'kind' => $kind,
                'student_id' => $pairing->student_id,
            ]);

            if ($key !== null) {
                app(HceCredentialAuth::class)->storeKey($card, $key, $reader);
            }

            return $this->consume($pairing, $reader, $card, rekeyed: false);
        });
    }

    /**
     * Close the armed window on a successful pair (or re-key).
     *
     * @return array{ok: true, card: Card, student: Student, pairing: PendingPairing, rekeyed: bool}
     */
    private function consume(PendingPairing $pairing, Reader $reader, Card $card, bool $rekeyed): array
    {
        // A success closes the whole loop: retire any older still-active row
        // so a success can never be shadowed by a stale window.
        PendingPairing::whereNull('consumed_at')
            ->where('id', '!=', $pairing->id)
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $pairing->update([
            'consumed_at' => now(),
            'reader_id' => $reader->id,
            'card_id' => $card->id, // audit trail for the pairing desk
        ]);

        return [
            'ok' => true,
            'card' => $card,
            'student' => $pairing->student,
            'pairing' => $pairing,
            'rekeyed' => $rekeyed,
        ];
    }
}
