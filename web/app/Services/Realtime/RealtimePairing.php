<?php

namespace App\Services\Realtime;

use App\Services\PairingService;
use Illuminate\Support\Facades\DB;

/**
 * El canal `pairing`: `pending_pairings` es la fuente de verdad y el
 * servidor solo difunde cambios. La firma hashea los DATOS de las filas
 * (armar, consumir, rechazar); el paso del tiempo no es un cambio — el
 * contador del escritorio corre en el cliente.
 */
final class RealtimePairing
{
    private const SIGNATURE_ROWS = 12;

    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function signature(): string
    {
        try {
            $rows = DB::table('pending_pairings')
                ->orderByDesc('id')
                ->limit(self::SIGNATURE_ROWS)
                ->get([
                    'id', 'student_id', 'reader_id', 'card_id',
                    'expires_at', 'consumed_at',
                    'last_rejected_uid', 'last_rejected_reason', 'last_rejected_at',
                ])
                ->map(fn ($row) => (array) $row)
                ->all();

            return md5(json_encode($rows));
        } catch (\Throwable) {
            // Base de datos a medio reemplazar: un latido silencioso es
            // mejor que un feed caído.
            return '';
        }
    }

    /**
     * El payload es PairingService::statusPayload() tal cual: el camino
     * WebSocket y el REST nunca pueden discrepar.
     *
     * @return array{pending: array<string, mixed>|null, last_pairing: array<string, mixed>|null, recent_pairings: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return $this->pairings->statusPayload();
    }
}
