@extends('layouts.app')

@section('title', 'Estaciones')
@section('heading', 'Estaciones')
@section('subtitle', 'Lectores con cámara y sus claves de acceso')

@php
    $onlineCount = count(array_filter($stations, fn (array $station) => $station['online']));
    $lastEventLabel = function (?string $timestamp): string {
        if ($timestamp === null) {
            return 'Sin eventos';
        }

        $minutes = (int) \Illuminate\Support\Carbon::parse($timestamp)->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => 'Hace unos segundos',
            $minutes < 60 => 'Hace '.$minutes.' min',
            $minutes < 1440 => 'Hace '.intdiv($minutes, 60).' h',
            default => 'Hace '.intdiv($minutes, 1440).' d',
        };
    };
@endphp

@section('content')
    @if (session('api_key'))
        <div class="secret-panel secret-panel--key" role="status">
            <span class="secret-panel__icon" aria-hidden="true"><x-icon name="key" /></span>
            <div class="secret-panel__body">
                <p class="secret-panel__title">Clave de la estación (se muestra una sola vez)</p>
                <p class="small">
                    Guárdala en el archivo <code>secrets.h</code> de la estación. Si la pierdes, rota la clave:
                    la anterior dejará de funcionar.
                </p>
                <div class="secret-row">
                    <code class="secret-code secret-code--key" id="station-api-key">{{ session('api_key') }}</code>
                    <button class="btn btn--outline btn--sm" type="button" data-copy-target="#station-api-key" data-copy-label="Copiar clave">
                        Copiar clave
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="toolbar">
        <div class="station-stats" aria-label="Resumen de estaciones">
            <span class="stat-pill"><strong>{{ count($stations) }}</strong> {{ count($stations) === 1 ? 'estación' : 'estaciones' }}</span>
            <span class="stat-pill"><strong>{{ $onlineCount }}</strong> en línea</span>
            <span class="stat-pill"><strong>{{ number_format($todayTotal, 0, ',', '.') }}</strong> depósitos hoy</span>
        </div>

        <button class="btn btn--primary" type="button" data-modal-open="modal-station">
            <x-icon name="plus" />
            Nueva estación
        </button>
    </div>

    <section class="card" aria-labelledby="stations-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="stations-title">Lectores registrados</h2>
                <p class="card__subtitle">
                    La huella identifica la clave sin mostrarla. Rotar invalida la clave anterior de inmediato.
                </p>
            </div>
        </div>

        <div class="card__body card__body--flush">
            <x-table-wrapper label="Estaciones" class="table-wrap--visible">
                <table class="data-table data-table--roomy admin-table">
                    <caption class="sr-only">Estaciones con su estado, actividad y huella de clave</caption>
                    <thead>
                        <tr>
                            <th scope="col">Estación</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Último contacto</th>
                            <th scope="col">Hoy</th>
                            <th scope="col">Clave de API</th>
                            <th scope="col" class="cell-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stations as $station)
                            <tr>
                                <td data-label="Estación">
                                    <div class="person-cell">
                                        <span class="class-avatar" aria-hidden="true">
                                            <x-icon name="station" :size="18" />
                                        </span>
                                        <div class="person-cell__body">
                                            <span class="cell-strong">{{ $station['label'] }}</span>
                                            <span class="cell-meta">Lector con cámara</span>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Estado">
                                    @if ($station['online'])
                                        <span class="chip chip--success">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            En línea
                                        </span>
                                    @else
                                        <span class="chip chip--danger">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            Sin conexión
                                        </span>
                                    @endif
                                </td>

                                <td data-label="Último contacto" class="small muted">
                                    {{ $lastEventLabel($station['last_event_at']) }}
                                </td>

                                <td data-label="Hoy" class="cell-figure">
                                    {{ number_format($station['today'], 0, ',', '.') }}
                                </td>

                                <td data-label="Clave de API">
                                    <code class="fingerprint">{{ $station['fingerprint'] }}</code>
                                </td>

                                <td data-label="Acciones" class="cell-right">
                                    <div class="row-actions">
                                        <form method="POST" action="{{ route('admin.stations.rotate', $station['id']) }}">
                                            @csrf
                                            <button class="btn btn--outline btn--sm" type="submit" title="Genera una clave nueva y muestra la anterior por última vez">
                                                Rotar clave
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.stations.destroy', $station['id']) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                class="btn btn--ghost btn--sm"
                                                type="submit"
                                                @disabled($station['has_history'])
                                                @if ($station['has_history']) aria-describedby="station-history-{{ $station['id'] }}" @endif
                                            >
                                                Eliminar
                                            </button>
                                        </form>

                                        @if ($station['has_history'])
                                            <span class="cell-meta" id="station-history-{{ $station['id'] }}">
                                                Tiene historial: no se puede eliminar, solo rotar.
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty
                                        icon="station"
                                        title="Todavía no hay estaciones"
                                        text="Crea la primera estación para que el parlante pueda registrar depósitos."
                                    >
                                        <button class="btn btn--primary" type="button" data-modal-open="modal-station">
                                            <x-icon name="plus" />
                                            Nueva estación
                                        </button>
                                    </x-empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-wrapper>
        </div>
    </section>

    <dialog class="modal" id="modal-station" aria-labelledby="modal-station-title" @if (old('_form') === 'station') data-modal-auto-open @endif>
        <form class="modal__panel" method="POST" action="{{ route('admin.stations.store') }}">
            @csrf
            <input type="hidden" name="_form" value="station">

            <div class="modal__header">
                <div>
                    <h2 class="modal__title" id="modal-station-title">Nueva estación</h2>
                    <p class="modal__subtitle">La clave se muestra una sola vez al crearla.</p>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Cerrar">
                    <x-icon name="error" :size="18" />
                </button>
            </div>

            <div class="modal__body">
                <x-field name="label" label="Nombre de la estación" placeholder="Estación Cafetería" icon="station" required autofocus help="Un nombre que el personal reconozca: bloque, piso o punto." />
            </div>

            <div class="modal__footer">
                <button class="btn btn--outline" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn--primary" type="submit">Crear estación</button>
            </div>
        </form>
    </dialog>
@endsection
