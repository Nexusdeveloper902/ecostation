<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /manifest.webmanifest — público y sin sesión. La app Android lo
 * sondea en la barrido de subred y acepta un host solo cuando responde
 * 200 con un cuerpo que contiene `"start_url"`.
 */
class ManifestController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'start_url' => '/',
            'display' => 'standalone',
            'lang' => 'es',
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
