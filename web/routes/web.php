<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PasswordController;
use App\Support\HomeTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Landing -> login (or the role-appropriate page when authenticated).
Route::get('/', function (Request $request) {
    $user = $request->user();

    if ($user === null) {
        return redirect()->route('login');
    }

    return redirect()->to(HomeTarget::for($user));
})->name('home');

// Guest-only. Login is throttled (the `login` limiter: 5/min per
// email+IP) — a brute-force guard with no UX change for a human.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Password rotation. The form lives outside any role gate (it is the
// exemption target of the rotation wall); the update clears the flag and
// lands on the role's own page.
Route::middleware('auth')->group(function () {
    Route::get('/password/change', [PasswordController::class, 'edit'])
        ->name('password.change');

    Route::put('/password/change', [PasswordController::class, 'update'])
        ->name('password.update');

    // The speaker-app account: web landing page only.
    Route::get('/station', fn () => view('station'))
        ->middleware('role:station')
        ->name('station');
});
