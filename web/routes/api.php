<?php

use App\Http\Controllers\Api\V1\CardPairingController;
use App\Http\Controllers\Api\V1\TapEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware('reader.auth')->group(function () {
    Route::post('/events/tap', [TapEventController::class, 'store'])->name('api.v1.events.tap');
    Route::post('/admin/cards/pair', [CardPairingController::class, 'store'])->name('api.v1.cards.pair');
});
