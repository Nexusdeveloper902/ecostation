<?php

// PHP2Shell (CVE-2026-109618 / CVE-2026-109619): every PHP runtime from 5.4
// through 8.5.11 allows unauthenticated RCE. Fail closed instead of running
// a vulnerable interpreter. Patched: 8.5.12, 8.4.27, 8.3.36, 8.2.35.
if (PHP_VERSION_ID < 80512) {
    $message = 'EcoStation requiere PHP 8.5.12 o superior (PHP2Shell, CVE-2026-109618/109619).';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message.PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit(1);
}

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveReaderToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'reader.auth' => ResolveReaderToken::class,
            'role' => EnsureRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        // Force password rotation on every real page and API route; the
        // middleware itself exempts the login/logout/password routes.
        $middleware->appendToGroup('web', EnsurePasswordChanged::class);
        $middleware->appendToGroup('api', EnsurePasswordChanged::class);

        // A password change/rotation invalidates every session that still
        // carries the old password hash (remember-me included).
        $middleware->appendToGroup('web', AuthenticateSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
