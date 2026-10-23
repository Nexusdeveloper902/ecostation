<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\HceCredentialKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Credential lifecycle. Revoke and unpair both keep the card row as
 * `revoked` and destroy the phone key: events, deposits and the points
 * ledger must survive a credential lifecycle change. A revoked card may
 * later be deliberately reactivated by arming a pairing window and
 * scanning the same credential (see PairingService).
 */
class CardController extends Controller
{
    public function revoke(Card $card): RedirectResponse
    {
        DB::transaction(function () use ($card): void {
            $card->update(['status' => CardStatus::Revoked]);

            HceCredentialKey::where('card_id', $card->id)->delete();
        });

        return redirect()->route('admin.pairing')
            ->with('status', 'Credencial revocada: '.$card->credential_uid.'.');
    }

    public function destroy(Card $card): RedirectResponse
    {
        $uid = $card->credential_uid;

        // Unpairing is a status change, NEVER a delete: the row is the
        // parent of events/deposits and deleting it would cascade the
        // student's history away. The key goes, so the credential is inert.
        DB::transaction(function () use ($card): void {
            $card->update(['status' => CardStatus::Revoked]);

            HceCredentialKey::where('card_id', $card->id)->delete();
        });

        return redirect()->route('admin.pairing')
            ->with('status', 'Credencial desemparejada: '.$uid.'. El historial se conserva.');
    }
}
