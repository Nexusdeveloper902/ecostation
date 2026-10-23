<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\HceCredentialKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Credential lifecycle. Revoke keeps history (the row stays `revoked`)
 * and destroys a phone credential's key; unpair deletes the row, which
 * is what makes the credential id fresh for a future pairing.
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

        $card->delete();

        return redirect()->route('admin.pairing')
            ->with('status', 'Credencial desemparejada: '.$uid.'.');
    }
}
