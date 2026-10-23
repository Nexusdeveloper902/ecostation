<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reader;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The station/key desk. A key leaves the server exactly once, in the
 * flash of the minting or rotating response (the reader-API-key rule);
 * the index renders only the public fingerprint and the last event time.
 */
class StationController extends Controller
{
    public function index(): View
    {
        $todayCounts = DB::table('events')
            ->where('occurred_at', '>=', Carbon::today())
            ->groupBy('reader_id')
            ->selectRaw('reader_id, count(*) as items')
            ->pluck('items', 'reader_id');

        $stations = Reader::query()
            ->withCount('events')
            ->withMax('events', 'occurred_at')
            ->orderBy('label')
            ->get()
            ->map(function (Reader $reader) use ($todayCounts): array {
                $lastAt = $reader->events_max_occurred_at !== null
                    ? Carbon::parse($reader->events_max_occurred_at)
                    : null;

                return [
                    'id' => (int) $reader->id,
                    'label' => (string) $reader->label,
                    'fingerprint' => DeviceRequestSigner::fingerprint((string) $reader->api_key),
                    'last_event_at' => $reader->events_max_occurred_at,
                    'today' => (int) ($todayCounts[$reader->id] ?? 0),
                    'online' => $lastAt !== null && $lastAt->gte(now()->subMinutes(15)),
                    'has_history' => (int) $reader->events_count > 0,
                ];
            })
            ->all();

        return view('admin.stations', [
            'stations' => $stations,
            'todayTotal' => array_sum(array_column($stations, 'today')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:190'],
        ]);

        $key = Str::random(32);

        $reader = Reader::forceCreate([
            'label' => trim((string) $data['label']),
            'api_key' => $key,
        ]);

        return redirect()->route('admin.stations')->with([
            'status' => 'Estación creada: '.$reader->label,
            'api_key' => $key,
        ]);
    }

    public function rotateKey(Reader $reader): RedirectResponse
    {
        $key = Str::random(32);

        $reader->forceFill(['api_key' => $key])->save();

        return redirect()->route('admin.stations')->with([
            'status' => 'Clave rotada para '.$reader->label.'. La anterior ya no sirve.',
            'api_key' => $key,
        ]);
    }

    public function destroy(Reader $reader): RedirectResponse
    {
        $label = $reader->label;

        // Foreign keys would cascade the station's events (and their
        // deposits), erasing student history. Once a reader has produced
        // events it stays; the desk rotates its key instead.
        if ($reader->events()->exists()) {
            throw ValidationException::withMessages([
                'station' => "La estación {$label} tiene eventos registrados y no se puede eliminar. "
                    .'Usa "Rotar clave" para dejar de confiar en la clave actual sin borrar el historial.',
            ]);
        }

        $reader->delete();

        return redirect()->route('admin.stations')->with('status', 'Estación eliminada: '.$label);
    }
}
