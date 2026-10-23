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

        abort_if($path === null, 404, __('api.deposit_photo_missing'));

        $disk = Storage::disk('local');

        // Open FIRST: a file deleted between the scope check and this
        // point yields null here (404), not a metadata/500 surprise.
        try {
            $stream = $disk->readStream($path);
        } catch (\Throwable) {
            $stream = null;
        }

        abort_if(! is_resource($stream), 404, __('api.deposit_photo_missing'));

        // Metadata is best-effort: a vanished/corrupt file must still be
        // a 404, never a 500 after the authorization decision.
        try {
            $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        } catch (\Throwable) {
            $mime = 'application/octet-stream';
        }

        $extension = $this->safeExtension($path, $mime);
        $filename = 'deposito-'.$deposit->id.'.'.$extension;

        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
                // A photo may show students: never let a proxy/browser
                // reuse it, and never let a sniffing browser reinterpret it.
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** Extension from the stored path when sane, otherwise from the MIME. */
    private function safeExtension(string $path, string $mime): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{2,5}$/', $extension) === 1) {
            return $extension;
        }

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}
