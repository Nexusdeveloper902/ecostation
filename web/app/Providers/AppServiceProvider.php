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
    }
}
