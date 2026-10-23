<?php

namespace App\Console\Commands;

use App\Models\PendingCapture;
use App\Models\RecyclingDeposit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The photo-retention promise (Task 13): after N days (admin setting,
 * default 30) the FILE is deleted. The deposit, its event and the
 * points ledger stay — statistics never change because a photo aged
 * out.
 *
 * Integrity rules:
 *  - a pointer (`image_path`) is only cleared when the file is really
 *    gone; a failed unlink keeps the pointer so the next run retries;
 *  - stale OPEN captures (awaiting_card / validating) are purged too,
 *    not just terminal ones;
 *  - files under the capture directory that no row references are
 *    removed once older than the cutoff (crash residue);
 *  - deleting a file that is already gone is a no-op, so a rerun is safe.
 */
class PurgePhotosCommand extends Command
{
    protected $signature = 'photos:purge';

    protected $description = 'Elimina las fotos vencidas de depósitos y capturas (la clasificación y los puntos se conservan)';

    private int $deleted = 0;

    private int $failed = 0;

    public function handle(): int
    {
        $this->deleted = 0;
        $this->failed = 0;

        $cutoff = now()->subDays(settings()->photoRetentionDays());
        $disk = Storage::disk('local');

        $this->purgeDepositPhotos($disk, $cutoff);
        $this->sweep($disk, $cutoff);

        $this->info("Fotos eliminadas: {$this->deleted}");

        if ($this->failed > 0) {
            $this->warn("Fotos no eliminadas: {$this->failed} (se reintentarán en la próxima purga)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function purgeDepositPhotos(Filesystem $disk, \DateTimeInterface $cutoff): void
    {
        RecyclingDeposit::query()
            ->whereNotNull('image_path')
            ->whereHas('event', fn ($query) => $query->where('occurred_at', '<', $cutoff))
            ->chunkById(100, function ($deposits) use ($disk): void {
                foreach ($deposits as $deposit) {
                    $path = $deposit->image_path;

                    if ($path === null || $this->deleteFile($disk, $path, 'deposit', (int) $deposit->id) === 'failed') {
                        continue;
                    }

                    // File gone (just deleted or already absent): the
                    // pointer can go, the statistics stay.
                    $deposit->forceFill(['image_path' => null])->save();
                }
            });
    }

    /**
     * Capture rows (ANY state past retention — an abandoned
     * awaiting_card/validating capture is residue too) plus orphan
     * files. Rows are only deleted once their file is gone, so a failed
     * unlink is retried on the next run instead of leaking a file with
     * no row to find it (the orphan pass would catch it, but keeping the
     * row makes the retry explicit).
     */
    private function sweep(Filesystem $disk, \DateTimeInterface $cutoff): void
    {
        PendingCapture::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($captures) use ($disk): void {
                foreach ($captures as $capture) {
                    $path = $capture->image_path;

                    if ($path !== null && $this->deleteFile($disk, $path, 'capture', (int) $capture->id) === 'failed') {
                        continue;
                    }

                    $capture->delete();
                }
            });

        // Files with no row referencing them: crash residue, failed
        // previous deletes after a row vanished, abandoned uploads.
        $referenced = RecyclingDeposit::query()
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->merge(PendingCapture::query()->whereNotNull('image_path')->pluck('image_path'))
            ->flip();

        foreach ($disk->allFiles('recycling-captures') as $path) {
            if ($referenced->has($path)) {
                continue;
            }

            try {
                $modified = $disk->lastModified($path);
            } catch (\Throwable) {
                continue;
            }

            if ($modified >= $cutoff->getTimestamp()) {
                continue;
            }

            $this->deleteFile($disk, $path, 'orphan', null);
        }
    }

    /**
     * @return 'deleted'|'missing'|'failed'
     */
    private function deleteFile(Filesystem $disk, string $path, string $kind, ?int $id): string
    {
        try {
            if (! $disk->exists($path)) {
                return 'missing';
            }

            if ($disk->delete($path)) {
                $this->deleted++;

                return 'deleted';
            }
        } catch (\Throwable $e) {
            Log::warning('photos:purge no pudo borrar el archivo', [
                'kind' => $kind,
                'id' => $id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            $this->failed++;

            return 'failed';
        }

        Log::warning('photos:purge no pudo borrar el archivo', [
            'kind' => $kind,
            'id' => $id,
            'path' => $path,
        ]);
        $this->failed++;

        return 'failed';
    }
}
