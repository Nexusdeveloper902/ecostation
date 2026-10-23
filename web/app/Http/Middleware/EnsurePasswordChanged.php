<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced first-login password rotation.
 *
 * Auto-provisioned student accounts ship with a shared, documented
 * initial password, so they carry users.must_change_password=true until
 * the owner rotates it. Flagged users may open only the password-change
 * form and logout; every other web page bounces them to the form.
 * JSON/API callers get a 403 instead of an HTML redirect (a 302 to a
 * Blade page would corrupt their contract).
 */
class EnsurePasswordChanged
{
    /** Route names a flagged user may still open. */
    public const EXEMPT = [
        'login',
        'logout',
        'password.change',
        'password.update',
        'api.v1.auth.password',
        'api.v1.auth.token.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // The api group runs BEFORE route auth:sanctum, so $request->user()
        // (web guard) is null for Bearer-PAT callers — resolve sanctum on
        // demand too, or flagged PAT traffic walks straight through.
        $user = $request->user() ?? $request->user('sanctum');

        // Guards memoize the user per app lifetime: read the flag from the
        // database (one indexed PK read), never from the memoized instance.
        $flagged = $user instanceof User
            && (bool) User::whereKey($user->getKey())->value('must_change_password');

        if (! $flagged) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // Unnamed routes (the 404 fallback) keep their verdict — the wall
        // only guards real pages, it never invents redirects.
        if ($routeName === null || in_array($routeName, self::EXEMPT, true)) {
            return $next($request);
        }

        // api/* keeps the app's JSON verdict; a web page (even one asked
        // for JSON, like the speaker's /realtime/token) keeps the redirect
        // the plans pin, so a flagged account cannot mint a feed token.
        if ($request->is('api/*')) {
            abort(403, __('api.password_change_required'));
        }

        return redirect()->route('password.change')
            ->with('status', __('auth.must_change_password'));
    }
}
