<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for web/API users: `role:admin`, `role:station`, or
 * `role:admin,teacher` (any of). Guest requests pass through to the auth
 * middleware (which returns a JSON 401 for API clients).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request); // let auth/auth:sanctum handle guests
        }

        $role = $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role;

        if (! in_array($role, $roles, true)) {
            abort(403, __('api.forbidden_role'));
        }

        return $next($request);
    }
}
