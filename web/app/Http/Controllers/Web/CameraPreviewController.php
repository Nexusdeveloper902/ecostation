<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/** Admin/teacher camera page: embeds the station's live JPEG preview. */
class CameraPreviewController extends Controller
{
    public function __invoke(): View
    {
        return view('camera.preview', [
            'url' => settings()->cameraPreviewUrl(),
        ]);
    }
}
