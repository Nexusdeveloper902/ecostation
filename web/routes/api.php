<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CardPairingController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RecyclingCaptureController;
use App\Http\Controllers\Api\V1\RecyclingClassificationController;
use App\Http\Controllers\Api\V1\TapEventController;
use App\Http\Controllers\Web\RealtimeTokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    // Student app: token mint (guest), rotation and revoke (Bearer).
    Route::post('/auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('api.v1.auth.token');

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('/auth/token', [AuthTokenController::class, 'destroy'])
            ->name('api.v1.auth.token.destroy');
        Route::put('/auth/password', [AuthTokenController::class, 'updatePassword'])
            ->name('api.v1.auth.password');

        Route::get('/me/balance', [MeController::class, 'balance'])
            ->middleware('role:student')
            ->name('api.v1.me.balance');
        Route::get('/me/history', [MeController::class, 'history'])
            ->middleware('role:student')
            ->name('api.v1.me.history');

        Route::get('/recycling/leaderboard', [LeaderboardController::class, 'show'])
            ->middleware('role:admin,teacher,student')
            ->name('api.v1.recycling.leaderboard');

        // Future token-based speaker: Sanctum station only.
        Route::get('/realtime/token', [RealtimeTokenController::class, 'issue'])
            ->middleware('role:station')
            ->name('api.v1.realtime.token');
    });

    // Station devices (Pulse-HMAC), with a modest per-key flood guard.
    Route::middleware(['reader.auth', 'throttle:reader'])->group(function () {
        Route::post('/events/tap', [TapEventController::class, 'store'])->name('api.v1.events.tap');
        Route::post('/recycling/classify', [RecyclingClassificationController::class, 'store'])
            ->name('api.v1.recycling.classify');
        Route::post('/recycling/capture', [RecyclingCaptureController::class, 'store'])
            ->name('api.v1.recycling.capture');
        Route::post('/recycling/captures/{capture}/associate', [RecyclingCaptureController::class, 'associate'])
            ->whereNumber('capture')
            ->name('api.v1.recycling.captures.associate');
        Route::post('/admin/cards/pair', [CardPairingController::class, 'store'])->name('api.v1.cards.pair');
    });
});
