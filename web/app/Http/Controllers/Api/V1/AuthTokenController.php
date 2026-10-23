<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The Android app's token surface (Task 12).
 *
 * POST   /api/v1/auth/token    — mint a Sanctum token for a STUDENT only.
 *                                Staff/station accounts and bad credentials
 *                                answer the same 422 on `email`, so the app
 *                                cannot tell them apart.
 * DELETE /api/v1/auth/token    — revoke the presented token.
 * PUT    /api/v1/auth/password — rotate the password (clears the flag).
 */
class AuthTokenController extends Controller
{
    /** bcrypt only reads the first 72 bytes; longer secrets must be refused. */
    private const PASSWORD_MAX = 72;

    /**
     * A valid bcrypt hash used for unknown/non-student accounts so the
     * response time never reveals whether the email exists.
     */
    private const DUMMY_HASH = '$2y$12$AxBI5NQLNwhBDcUPvG07h.QFg85hLImkNtTqo.q1JMEuC83v0srbO';

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Always pay one hash check, even for an unknown email or a staff
        // account: the identical 422 must also cost the same time.
        $hash = $user !== null && $user->isStudent() ? $user->password : self::DUMMY_HASH;
        $passwordOk = Hash::check($validated['password'], $hash);

        if ($user === null || ! $user->isStudent() || ! $passwordOk) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $student = $user->student;

        return response()->json([
            'token' => $user->createToken($validated['device_name'], ['student'])->plainTextToken,
            'must_change_password' => (bool) $user->must_change_password,
            'student' => [
                'id' => $student?->id,
                'name' => $student?->name ?? $user->name,
                'class_name' => $student?->schoolClass?->name,
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function updatePassword(Request $request): Response
    {
        $user = $request->user();

        // `current_password` cannot use the built-in rule here: a Bearer
        // request authenticates on the sanctum guard, and the rule looks
        // at the default guard's user.
        $validated = $request->validate([
            'current_password' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (! Hash::check((string) $value, $user->password)) {
                    $fail(__('auth.password'));
                }
            }],
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

        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->save();

        // The phone that rotated keeps its token; every other device is
        // logged out. A session-authenticated caller has no current PAT,
        // so all tokens are revoked.
        $current = $user->currentAccessToken();
        $tokens = $user->tokens();

        if ($current instanceof PersonalAccessToken) {
            $tokens->whereKeyNot($current->getKey());
        }

        $tokens->delete();

        return response()->noContent();
    }
}
