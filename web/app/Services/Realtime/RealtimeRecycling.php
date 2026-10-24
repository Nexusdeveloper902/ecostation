<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * La mitad de LECTURA del canal `recycling`: `recycling_updates` es la
 * única fuente de difusión. Las filas las escriben transaccionalmente
 * quienes ejecutan el cambio; el servidor solo sondea y empuja.
 */
final class RealtimeRecycling
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function updatesAfter(int $updateId, int $limit = 100): array
    {
        return $this->rows($limit, 'asc', false, $updateId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        return $this->rows($limit, 'desc', true, null);
    }

    /**
     * Every update belonging to the given events. The `hello` uses this so a
     * historical tap always arrives with its material/points, even when the
     * generic recent-window would have scrolled past its two updates.
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, array<string, mixed>>
     */
    public function forEventIds(array $eventIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $eventIds)));

        if ($ids === []) {
            return [];
        }

        return DB::table('recycling_updates')
            ->whereIn('payload->event_id', $ids)
            ->orderBy('id')
            ->get(['id', 'type', 'payload', 'created_at'])
            ->map(fn ($row): array => $this->map($row))
            ->all();
    }

    public function latestUpdateId(): int
    {
        try {
            return (int) (DB::table('recycling_updates')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $limit, string $direction, bool $reverse, ?int $afterId): array
    {
        $query = DB::table('recycling_updates');

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query
            ->orderBy('id', $direction)
            ->limit($limit)
            ->get(['id', 'type', 'payload', 'created_at'])
            ->map(fn ($row): array => $this->map($row))
            ->all();

        return $reverse ? \array_reverse($rows) : $rows;
    }

    /** @return array<string, mixed> */
    private function map(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);

        return [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'payload' => is_array($payload) ? $payload : [],
            'at' => (string) $row->created_at,
        ];
    }
}
