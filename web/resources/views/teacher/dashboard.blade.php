@extends('layouts.app')

@section('title', 'Resumen')
@section('heading', 'Resumen')
@section('subtitle', 'Cómo reciclan tus cursos')

@section('actions')
    <a class="btn btn--outline btn--sm" href="{{ route('teacher.export') }}">
        <x-icon name="download" />
        Exportar
    </a>
@endsection

@section('filters')
    <form method="GET" action="{{ route('teacher.dashboard') }}" class="filters">
        <x-select
            name="class"
            label="Curso"
            :options="['' => 'Todos mis cursos'] + $classes->pluck('name', 'id')->all()"
            :selected="$selectedClass?->id"
            icon="users"
            class="filters__field"
        />

        <x-select
            name="range"
            label="Periodo"
            :options="$ranges"
            :selected="$range"
            icon="calendar"
            class="filters__field"
        />

        <button class="btn btn--outline btn--sm" type="submit">Aplicar</button>
    </form>
@endsection

@section('content')
    <div class="kpi-grid">
        <x-kpi
            label="Depósitos"
            :value="number_format($summary['items'], 0, ',', '.')"
            :hint="$summary['deltaPct'] !== null
                ? ($summary['deltaPct'] > 0 ? '+' : '').$summary['deltaPct'].' % vs. el periodo anterior'
                : null"
            :hint-tone="$summary['deltaPct'] !== null && $summary['deltaPct'] > 0 ? 'up' : null"
        />
        <x-kpi
            label="Puntos otorgados"
            :value="number_format($summary['points'], 0, ',', '.')"
            :hint="'Promedio '.number_format($summary['averagePoints'], 0, ',', '.').' por estudiante'"
        />
        <x-kpi
            label="Estudiantes activos"
            :value="$summary['activeStudents'].' de '.$summary['totalStudents']"
            text
            :hint="$summary['totalStudents'] - $summary['activeStudents'].' sin depósitos en el periodo'"
        />
        <x-kpi
            label="Reciclable"
            :value="$summary['recyclablePct'].' %'"
            :hint="(100 - $summary['recyclablePct']).' % clasificado como otro'"
        />
    </div>

    <section class="card" aria-labelledby="tendencia-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="tendencia-title">Depósitos por material, por semana</h2>
                <p class="card__subtitle">
                    Del {{ \Illuminate\Support\Carbon::parse($from)->locale('es')->translatedFormat('j M') }}
                    al {{ \Illuminate\Support\Carbon::parse($to)->locale('es')->translatedFormat('j M Y') }}
                </p>
            </div>
        </div>

        <div class="card__body">
            @include('partials.teacher.material-chart', ['weeks' => $materialTrend, 'byMaterial' => $byMaterial])
        </div>
    </section>

    <div class="grid-2">
        <section class="card" aria-labelledby="distribucion-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="distribucion-title">Distribución por material</h2>
                    <p class="card__subtitle">Depósitos y puntos del periodo</p>
                </div>
            </div>

            <div class="card__body">
                @include('partials.student.material-bars', ['byMaterial' => $byMaterial])
            </div>
        </section>

        <section class="card" aria-labelledby="activos-title">
            <div class="card__header">
                <h2 class="card__title" id="activos-title">Estudiantes más activos</h2>
                @if (count($byClass) > 0)
                    <a class="card__link" href="{{ route('teacher.class', $byClass[0]['class_id']) }}">Ver todos</a>
                @endif
            </div>

            <div class="card__body">
                @if (count($topStudents) === 0)
                    <x-empty icon="users" title="Sin actividad todavía" text="Cuando tus estudiantes depositen, los más activos aparecerán aquí." />
                @else
                    <div class="deposit-list">
                        @foreach ($topStudents as $entry)
                            <a class="person-row" href="{{ route('teacher.student', $entry['student_id']) }}">
                                <x-avatar :name="$entry['name']" />
                                <div class="person-row__main">
                                    <p class="person-row__name">{{ $entry['name'] }}</p>
                                    <p class="person-row__meta">
                                        {{ number_format($entry['items'], 0, ',', '.') }} depósitos
                                        @if ($entry['class_name']) · {{ $entry['class_name'] }} @endif
                                    </p>
                                </div>
                                <span class="person-row__value">{{ number_format($entry['points'], 0, ',', '.') }} pts</span>
                                <x-icon name="chevron-right" :size="18" class="muted" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="cursos-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="cursos-title">Tus cursos</h2>
                <p class="card__subtitle">Ítems y puntos del periodo, por curso</p>
            </div>
        </div>

        <x-table-wrapper label="Resumen por curso">
            <table class="data-table data-table--fit">
                <caption class="sr-only">Resumen por curso</caption>
                <thead>
                    <tr>
                        <th scope="col">Curso</th>
                        <th scope="col">Depósitos</th>
                        <th scope="col" class="cell-right">Puntos</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byClass as $row)
                        <tr>
                            <td>
                                <a class="cell-strong" href="{{ route('teacher.class', $row['class_id']) }}">{{ $row['class_name'] }}</a>
                            </td>
                            <td class="cell-figure">{{ number_format($row['items'], 0, ',', '.') }}</td>
                            <td class="cell-right cell-figure">{{ number_format($row['points'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="muted">No hay cursos en tu ámbito.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-table-wrapper>
    </section>
@endsection
