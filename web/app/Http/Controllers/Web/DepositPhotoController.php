<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RecyclingDeposit;
use App\Services\StudentScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single authorized door to a deposit photo (Task 13).
 *
 * Photos live on the PRIVATE `local` disk and may show students, so
 * they are never on a public disk. Admins see every photo; teachers see
 * only photos of students in their own classes; students never reach
 * this route (role gate). A null path, a purged file or an
 * out-of-scope student answers 404 — the same answer, so a teacher
 * cannot probe whether another class's photo exists.
 */
class DepositPhotoController extends Controller
{
    public function show(Request $request, RecyclingDeposit $deposit): StreamedResponse
    {
        $deposit->loadMissing('event.card.student');
        $student = $deposit->event?->card?->student;

        // A historical artifact is scoped by the class SNAPSHOT on the
        // event, not by where the student sits today: a transfer must
        // never move old photos between teachers.
        $classId = $deposit->event?->class_id !== null ? (int) $deposit->event->class_id : null;

        abort_if(
            $student === null
            || ! StudentScope::forUser($request->user())->allows((int) $student->id, $classId),
            404,
            __('api.deposit_photo_missing'),
        );

        $path = $deposit->image_path;

        abort_if(
            $path === null || ! Storage::disk('local')->exists($path),
            404,
            __('api.deposit_photo_missing'),
        );

        return Storage::disk('local')->response(
            $path,
            'deposito-'.$deposit->id,
            [
                'Content-Type' => Storage::disk('local')->mimeType($path),
                'Cache-Control' => 'private, max-age=60',
            ],
        );
    }
}
