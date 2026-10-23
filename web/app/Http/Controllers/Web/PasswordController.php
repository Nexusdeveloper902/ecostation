<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\HomeTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
                'confirmed',
                'different:current_password',
                Rule::notIn(array_filter([settings()->studentInitialPassword()])),
            ],
        ]);

        $user = $request->user();
        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->save();

        // A stolen pre-rotation session must not survive the rotation.
        $request->session()->regenerate();

        return redirect()->to(HomeTarget::for($user))
            ->with('status', __('auth.password_changed'));
    }
}
