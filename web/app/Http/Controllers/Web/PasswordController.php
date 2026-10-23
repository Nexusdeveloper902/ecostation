<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\HomeTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Forced (and voluntary) password rotation.
 *
 * GET  /password/change — the rotation form. Open to any authenticated
 * user (forced for flagged accounts, voluntary for everyone else).
 *
 * PUT  /password/change — validates the current password plus a
 * confirmed minimum-8 replacement, stores it, clears
 * users.must_change_password, and lands on the role's own page.
 */
class PasswordController extends Controller
{
    /** bcrypt only reads the first 72 bytes; longer secrets must be refused. */
    private const PASSWORD_MAX = 72;

    public function edit(): View
    {
        return view('auth.password');
    }

    public function update(Request $request): RedirectResponse
    {
        // Without `different` + the initial-value blacklist, submitting the
        // CURRENT (shared, documented) value as the "new" password would
        // clear the flag and leave the account on the well-known secret.
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:'.self::PASSWORD_MAX,
                'confirmed',
                'different:current_password',
                Rule::notIn(array_filter([settings()->studentInitialPassword()])),
            ],
        ]);

        $user = $request->user();
        $user->password = $validated['password'];
        $user->must_change_password = false;

        // A stolen remember-me cookie must not survive either.
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Every API token minted before the rotation dies; a web session
        // has no current PAT, so all of them go.
        $currentToken = $user->currentAccessToken();
        $tokens = $user->tokens();

        if ($currentToken instanceof PersonalAccessToken) {
            $tokens->whereKeyNot($currentToken->getKey());
        }

        $tokens->delete();

        // Drop every other real session row for this user. The current
        // row is kept and then regenerated below; DB rows belong to the
        // user only, so another user's session is never touched.
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        // A stolen pre-rotation session must not survive the rotation.
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->to(HomeTarget::for($user))
            ->with('status', __('auth.password_changed'));
    }
}
