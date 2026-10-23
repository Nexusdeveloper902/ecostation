<?php

namespace App\Console\Commands;

use App\Enums\PendingCaptureState;
use App\Models\PendingCapture;
use App\Models\RecyclingDeposit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The photo-retention promise (Task 13): after N days (admin setting,
 * default 30) the FILE is deleted. The deposit, its event and the
 * points ledger stay — statistics never change because a photo aged
 * out. Deleting a file that is already gone is a no-op, so a rerun is
 * safe, and an accepted capture's file (the deposit's own file) is
 * never counted twice.
 */
class PurgePhotosCommand extends Command
{
    protected $signature = 'photos:purge';

    protected $description = 'Elimina las fotos vencidas de depósitos y capturas (la clasificación y los puntos se conservan)';

    public function handle(): int
    {
        $cutoff = now()->subDays(settings()->photoRetentionDays());
        $deleted = 0;

        // 1) Deposit photos past retention: file first, then the pointer.
        RecyclingDeposit::query()
            ->whereNotNull('image_path')
            ->whereHas('event', fn ($query) => $query->where('occurred_at', '<', $cutoff))
            ->chunkById(100, function ($deposits) use (&$deleted): void {
                foreach ($deposits as $deposit) {
                    $path = $deposit->image_path;

                    if ($path !== null && Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                        $deleted++;
                    }

                    $deposit->forceFill(['image_path' => null])->save();
                }
            });

        // 2) Terminal pending captures past retention: file first, then the row.
        $terminal = [
            PendingCaptureState::Expired->value,
            PendingCaptureState::Rejected->value,
            PendingCaptureState::Failed->value,
            PendingCaptureState::Accepted->value,
        ];

        PendingCapture::query()
            ->whereIn('state', $terminal)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($captures) use (&$deleted): void {
                foreach ($captures as $capture) {
                    $path = $capture->image_path;

                    if ($path !== null && Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                        $deleted++;
                    }

                    $capture->delete();
                }
            });

        $this->info("Fotos eliminadas: {$deleted}");

        return self::SUCCESS;
    }
}
