@extends('layouts.app')

@section('title', 'En vivo')
@section('heading', 'En vivo')
@section('subtitle', 'Depósitos de tus cursos a medida que llegan')

@section('actions')
    <span class="chip chip--neutral live-status" data-live-status data-live-state="idle" role="status" aria-live="polite">
        <span class="live-dot" data-live-dot></span>
        <span data-live-status-text>Sin conexión en vivo</span>
    </span>
@endsection

@section('filters')
    <form method="GET" action="{{ route('teacher.live') }}" class="filters">
        <x-select
            name="class"
            label="Cursos"
            :options="['' => 'Todos mis cursos'] + $classes->pluck('name', 'id')->all()"
            :selected="$selectedClass?->id"
            icon="users"
            class="filters__field"
        />

        <button class="btn btn--outline btn--sm" type="submit">Aplicar</button>

        <span class="filter-pill">
            <x-icon name="station" />
            Estación: todas
        </span>
    </form>
@endsection

@section('content')
    <div class="live-layout">
        <section
            class="card live-feed"
            aria-labelledby="live-title"
            data-realtime-token-url="{{ $realtimeTokenUrl }}"
            data-live-feed
            data-live-class-ids="{{ implode(',', $classIds ?? []) }}"
            data-live-selected-class="{{ $selectedClass?->id }}"
        >
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="live-title">Depósitos en tiempo real</h2>
                    <p class="card__subtitle">
                        Se actualiza solo. La clasificación tarda unos segundos; el estudiante no espera en la estación.
                    </p>
                </div>
            </div>

            <div class="card__body card__body--flush" data-live-rows>
                @forelse (array_reverse($recent) as $event)
                    <article
                        class="live-row"
                        data-live-row
                        data-event-id="{{ $event['id'] }}"
                        data-student-id="{{ $event['student_id'] }}"
                        data-class-id="{{ $event['class_id'] }}"
                        data-reader="{{ $event['reader_label'] }}"
                        data-occurred-at="{{ $event['date'].' '.$event['time'] }}"
                    >
                        <span class="live-row__time">{{ $event['time'] }}</span>

                        <div class="live-row__student">
                            <x-avatar :name="$event['student_name']" size="sm" />
                            <div>
                                <span class="live-row__name">{{ $event['student_name'] }}</span>
                                <span class="live-row__class">{{ $event['class_name'] ?? 'Sin curso' }}</span>
                            </div>
                        </div>

                        <div class="live-row__material">
                            @if ($event['material'])
                                <x-material-badge :material="$event['material']" />
                            @else
                                <span class="chip chip--warning">
                                    <span class="chip__dot" aria-hidden="true"></span>
                                    Clasificando…
                                </span>
                            @endif
                        </div>

                        <span class="live-row__station">{{ $event['reader_label'] ?? 'Sin estación' }}</span>

                        <div class="live-row__status">
                            @if ($event['points'] !== null)
                                <x-points-badge :points="$event['points']" />
                            @else
                                <span class="muted" aria-label="Puntos pendientes">—</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <div data-live-empty>
                        <x-empty
                            icon="broadcast"
                            title="Esperando el primer toque"
                            text="Abre la estación en modo parlante. Los depósitos aparecerán aquí sin recargar la página."
                        />
                    </div>
                @endforelse
            </div>

            <div class="card__footer">
                <p class="small muted">
                    Las filas nuevas se insertan arriba cuando el feed entrega un toque.
                </p>
            </div>
        </section>

        <aside class="live-rail" aria-label="Estado de las estaciones">
            <section class="card" aria-labelledby="estaciones-title">
                <div class="card__header">
                    <h2 class="card__title" id="estaciones-title">Estaciones</h2>
                </div>

                <div class="card__body">
                    @if (count($stations) === 0)
                        <p class="small muted">Todavía no hay estaciones registradas.</p>
                    @else
                        <div class="station-list" data-live-stations>
                            @foreach ($stations as $station)
                                @php
                                    $minutes = $station['last_event'] ? (int) $station['last_event']->diffInMinutes(now()) : null;
                                    $ago = $minutes === null
                                        ? null
                                        : ($minutes < 60
                                            ? $minutes.' min'
                                            : ($minutes < 1440 ? intdiv($minutes, 60).' h' : intdiv($minutes, 1440).' d'));
                                @endphp

                                <div class="station-row">
                                    <div>
                                        <p class="station-row__name">{{ $station['label'] }}</p>
                                        <p class="station-row__meta">
                                            {{ number_format($station['today'], 0, ',', '.') }} hoy
                                            @if ($ago)
                                                · hace {{ $ago }}
                                            @else
                                                · sin señales
                                            @endif
                                        </p>
                                    </div>

                                    @if ($station['online'])
                                        <span class="chip chip--success">
                                            <span class="live-dot live-dot--on" aria-hidden="true"></span>
                                            En línea
                                        </span>
                                    @else
                                        <span class="chip chip--danger">
                                            <span class="live-dot live-dot--off" aria-hidden="true"></span>
                                            Sin señales
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section class="card" aria-labelledby="hoy-title">
                <div class="card__header">
                    <h2 class="card__title" id="hoy-title">Hoy en tus cursos</h2>
                </div>

                <div class="card__body">
                    <div class="stat-list">
                        <div class="stat-list__row">
                            <span class="stat-list__label">Depósitos</span>
                            <span class="stat-list__value">{{ number_format($today['items'], 0, ',', '.') }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Puntos</span>
                            <span class="stat-list__value">{{ number_format($today['points'], 0, ',', '.') }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Estudiantes</span>
                            <span class="stat-list__value">{{ number_format($today['activeStudents'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>
            </section>

            @if ($silentStation)
                @php
                    $silentAgo = $silentStation['minutes'] < 60
                        ? $silentStation['minutes'].' min'
                        : ($silentStation['minutes'] < 1440
                            ? intdiv($silentStation['minutes'], 60).' h'
                            : intdiv($silentStation['minutes'], 1440).' d');
                @endphp

                <div class="notice" role="status">
                    <x-icon name="warning" />
                    <p>
                        La {{ $silentStation['label'] }} no reporta desde hace
                        {{ $silentAgo }}. Avisa a coordinación si sigue así.
                    </p>
                </div>
            @endif
        </aside>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/realtime.js') }}" defer></script>
@endpush
