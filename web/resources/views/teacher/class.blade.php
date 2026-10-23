@extends('layouts.app')

@section('title')
    Curso {{ $class->name }}
@endsection
@section('heading', 'Estudiantes')
@section('subtitle', 'Detalle por estudiante de tus cursos')

@section('actions')
    <a class="btn btn--outline btn--sm" href="{{ route('teacher.export') }}">
        <x-icon name="download" />
        Exportar
    </a>
@endsection

@section('filters')
    <form method="GET" action="{{ route('teacher.class', $class) }}" class="filters">
        <x-select
            name="range"
            label="Periodo"
            :options="$ranges"
            :selected="$range"
            icon="calendar"
            class="filters__field"
        />

        <div class="field filters__field search-field">
            <label class="field__label" for="student-search">Buscar estudiante</label>
            <x-icon name="search" />
            <input
                class="input"
                id="student-search"
                name="q"
                type="search"
                value="{{ $search }}"
                placeholder="Buscar estudiante"
                autocomplete="off"
            >
        </div>

        <button class="btn btn--outline btn--sm" type="submit">Aplicar</button>

        <span class="filter-pill">
            <x-icon name="users" />
            Curso: {{ $class->name }}
        </span>
    </form>
@endsection

@section('content')
    <div class="teacher-split">
        <section class="card" aria-labelledby="lista-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="lista-title">Estudiantes del curso</h2>
                    <p class="card__subtitle">{{ count($students) }} estudiantes · {{ $class->name }}</p>
                </div>
            </div>

            @if (count($students) === 0)
                <div class="card__body">
                    <x-empty
                        icon="users"
                        title="Sin estudiantes para mostrar"
                        text="Este curso no tiene estudiantes o la búsqueda no coincide con ningún nombre."
                    />
                </div>
            @else
                <x-table-wrapper label="Estudiantes del curso">
                    <table class="data-table data-table--roomy data-table--fit">
                        <caption class="sr-only">Estudiantes de {{ $class->name }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Estudiante</th>
                                <th scope="col">Dep.</th>
                                <th scope="col" class="cell-right">Puntos</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($students as $row)
                                <tr>
                                    <td>
                                        <a class="person-cell person-cell--link" href="{{ route('teacher.student', $row['id']) }}">
                                            <x-avatar :name="$row['name']" size="sm" />
                                            <span>
                                                <span class="cell-strong">{{ $row['name'] }}</span>
                                                <span class="cell-meta">{{ $class->name }}</span>
                                            </span>
                                        </a>
                                    </td>
                                    <td class="cell-figure">{{ number_format($row['items'], 0, ',', '.') }}</td>
                                    <td class="cell-right">
                                        <span class="cell-figure">{{ number_format($row['points'], 0, ',', '.') }}</span>
                                        <x-icon name="chevron-right" :size="16" class="muted" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-wrapper>
            @endif
        </section>

        <aside class="stack" aria-label="Resumen del curso">
            <section class="card">
                <div class="card__header">
                    <div>
                        <h2 class="card__title">{{ $class->name }}</h2>
                        <p class="card__subtitle">
                            Del {{ \Illuminate\Support\Carbon::parse($from)->locale('es')->translatedFormat('j M') }}
                            al {{ \Illuminate\Support\Carbon::parse($to)->locale('es')->translatedFormat('j M Y') }}
                        </p>
                    </div>
                </div>

                <div class="card__body">
                    <div class="stat-list">
                        <div class="stat-list__row">
                            <span class="stat-list__label">Depósitos</span>
                            <span class="stat-list__value">{{ number_format($summary['items'], 0, ',', '.') }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Puntos otorgados</span>
                            <span class="stat-list__value">{{ number_format($summary['points'], 0, ',', '.') }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Estudiantes activos</span>
                            <span class="stat-list__value">{{ $summary['activeStudents'] }} de {{ $summary['totalStudents'] }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Reciclable</span>
                            <span class="stat-list__value">{{ $summary['recyclablePct'] }} %</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="materiales-curso">
                <div class="card__header">
                    <div>
                        <h2 class="card__title" id="materiales-curso">Materiales del curso</h2>
                        <p class="card__subtitle">Depósitos y puntos del periodo</p>
                    </div>
                </div>

                <div class="card__body">
                    @include('partials.student.material-bars', ['byMaterial' => $byMaterial])
                </div>
            </section>
        </aside>
    </div>
@endsection
