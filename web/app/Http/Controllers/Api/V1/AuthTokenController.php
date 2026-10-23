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
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user === null || ! $user->isStudent() || ! Hash::check($validated['password'], $user->password)) {
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
                'confirmed',
                'different:current_password',
                Rule::notIn([settings()->studentInitialPassword()]),
            ],
        ]);

        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->save();

        return response()->noContent();
    }
}
