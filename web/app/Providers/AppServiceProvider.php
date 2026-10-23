<?php

namespace App\Providers;

use App\Contracts\MaterialClassifier;
use App\Services\Recycling\ClassifierFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaterialClassifier::class, fn () => ClassifierFactory::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Brute-force guard on the session login: 5 attempts per minute
        // per email+IP. Keying by BOTH means one noisy account cannot
        // lock out a whole shared staff-room NAT, and one NAT cannot
        // spray many accounts from a single address.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        // Reader traffic is authenticated first; this blunts a stolen key
        // or a buggy device hammering the station API. Replay protection
        // stays in the HMAC verifier (single-use nonce).
        RateLimiter::for('reader', function (Request $request) {
            $header = (string) $request->header('Authorization');

            if (str_starts_with($header, 'Pulse-HMAC ')) {
                // Stable public key fingerprint; the signature/nonce change
                // on every request.
                $identity = explode(':', substr($header, strlen('Pulse-HMAC ')))[0];
            } else {
                $identity = $request->bearerToken() ?? (string) $request->ip();
            }

            return Limit::perMinute(max(1, (int) config('ecostation.device_auth.rate_limit_per_minute', 60)))
                ->by(hash('sha256', (string) $identity));
        });
    }
}
