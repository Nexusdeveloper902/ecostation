<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for web/API users: `role:admin`, `role:station`, or
 * `role:admin,teacher` (any of). A guest fails CLOSED with 401 instead
 * of being silently waved through when this middleware runs without an
 * auth middleware in front.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $role = $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role;

        if (! in_array($role, $roles, true)) {
            abort(403, __('api.forbidden_role'));
        }

        return $next($request);
    }
}
