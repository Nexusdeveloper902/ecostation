@extends('layouts.app')

@section('title', 'Ranking')
@section('heading', 'Ranking')
@section('subtitle', '¿Quién recicla más este año?')

@php
    $tab = request()->query('tab') === 'cursos' ? 'cursos' : 'estudiantes';
    $studentClass = $student->schoolClass?->name ?? 'Sin curso';
    $rest = array_values(array_filter($top, fn (array $entry) => $entry['rank'] > 3));
    $showMe = (int) $me['rank'] > 10;
@endphp

@section('filters')
    <x-tabs
        :items="[
            ['label' => 'Estudiantes', 'href' => route('student.leaderboard'), 'active' => $tab === 'estudiantes'],
            ['label' => 'Curso vs. curso', 'href' => route('student.leaderboard', ['tab' => 'cursos']), 'active' => $tab === 'cursos'],
        ]"
        label="Tipo de ranking"
    />

    <span class="filter-pill">
        <x-icon name="calendar" />
        Año escolar {{ now()->year }}
    </span>

    @if ($tab === 'estudiantes')
        <span class="filter-pill">
            <x-icon name="users" />
            Mi curso: {{ $studentClass }}
        </span>
    @endif
@endsection

@section('content')
    @if ($tab === 'estudiantes')
        <div class="rank-summary">
            <span class="rank-summary__label">Tu puesto</span>
            <span class="rank-summary__value">#{{ number_format($me['rank'], 0, ',', '.') }}</span>
            <span class="muted">·</span>
            <span class="mono">{{ number_format($me['points'], 0, ',', '.') }} pts</span>
            <span class="muted">en {{ $studentClass }}</span>
        </div>

        @if (count($top) >= 3)
            <div class="podium">
                @foreach ([1, 0, 2] as $index)
                    @php
                        $entry = $top[$index];
                        $rank = (int) $entry['rank'];
                    @endphp

                    <article class="podium__card podium__card--{{ $rank }}">
                        <span class="podium__rank" aria-label="Puesto {{ $rank }}">{{ $rank }}</span>
                        <x-avatar
                            :name="$entry['student_name']"
                            size="xl"
                            :tone="$rank === 1 ? 'sun' : 'mint'"
                            class="podium__avatar"
                        />
                        <p class="podium__name">{{ $entry['student_name'] }}</p>
                        <p class="podium__class">
                            {{ $entry['class_name'] ?? 'Sin curso' }}
                            @if ((int) $entry['student_id'] === (int) $student->id) · Tú @endif
                        </p>
                        <p class="podium__points">
                            {{ number_format($entry['points'], 0, ',', '.') }}
                            <span class="podium__unit">pts</span>
                        </p>
                    </article>
                @endforeach
            </div>
        @endif

        <section class="card" aria-label="Tabla de posiciones">
            <x-table-wrapper label="Ranking de estudiantes">
                <table class="data-table data-table--roomy data-table--fit">
                    <caption class="sr-only">Ranking de estudiantes por puntos</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Estudiante</th>
                            <th scope="col" class="cell-hide-sm">Depósitos</th>
                            <th scope="col" class="cell-right">Puntos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rest as $entry)
                            <tr @if ((int) $entry['student_id'] === (int) $student->id) class="is-me" @endif>
                                <td><span class="rank-pill rank-pill--{{ $entry['rank'] }}">{{ $entry['rank'] }}</span></td>
                                <td>
                                    <div class="person-cell">
                                        <x-avatar :name="$entry['student_name']" size="sm" />
                                        <div>
                                            <span class="cell-strong">{{ $entry['student_name'] }}</span>
                                            <span class="cell-meta">
                                                {{ $entry['class_name'] ?? 'Sin curso' }}
                                                @if ((int) $entry['student_id'] === (int) $student->id) · Tú @endif
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell-figure cell-hide-sm">
                                    {{ number_format((int) ($studentDeposits[$entry['student_id']] ?? 0), 0, ',', '.') }} depósitos
                                </td>
                                <td class="cell-right cell-figure">
                                    {{ number_format($entry['points'], 0, ',', '.') }} pts
                                </td>
                            </tr>
                        @endforeach

                        @if ($showMe)
                            <tr class="is-me">
                                <td><span class="rank-pill">{{ $me['rank'] }}</span></td>
                                <td>
                                    <div class="person-cell">
                                        <x-avatar :name="$student->name" size="sm" />
                                        <div>
                                            <span class="cell-strong">{{ $student->name }}</span>
                                            <span class="cell-meta">{{ $studentClass }} · Tú</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell-figure cell-hide-sm">
                                    {{ number_format((int) ($studentDeposits[$student->id] ?? 0), 0, ',', '.') }} depósitos
                                </td>
                                <td class="cell-right cell-figure">{{ number_format($me['points'], 0, ',', '.') }} pts</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </x-table-wrapper>
        </section>

        <p class="small muted">
            Puntos totales del año escolar {{ now()->year }} · tu curso ({{ $studentClass }}).
            Los empates comparten el mismo puesto.
        </p>
    @else
        @php
            $maxAverage = max(array_column($classes, 'avg_points')) ?: 1;
        @endphp

        <section class="card" aria-labelledby="promedio-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="promedio-title">Puntos por estudiante</h2>
                    <p class="card__subtitle">Promedio por estudiante para que los cursos grandes y pequeños compitan en igualdad.</p>
                </div>
            </div>

            <div class="card__body">
                <div class="bars">
                    @foreach ($classes as $class)
                        <div class="bar-row">
                            <div class="bar-row__head">
                                <span class="class-name">{{ $class['class_name'] }}</span>
                                <span class="bar-row__value">{{ number_format($class['avg_points'], 0, ',', '.') }} pts</span>
                            </div>
                            <div
                                class="bar"
                                role="img"
                                aria-label="{{ $class['class_name'].': '.number_format($class['avg_points'], 0, ',', '.').' puntos por estudiante' }}"
                            >
                                <span
                                    class="bar__fill {{ (int) $class['class_id'] === (int) $student->class_id ? 'bar__fill--strong' : 'bar__fill--green' }}"
                                    style="width: {{ $class['avg_points'] > 0 ? max(2, round($class['avg_points'] / $maxAverage * 100)) : 0 }}%"
                                ></span>
                            </div>
                            @if ((int) $class['class_id'] === (int) $student->class_id)
                                <span class="chip chip--sun">Tu curso</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="card" aria-label="Ranking de cursos">
            <x-table-wrapper label="Ranking de cursos">
                <table class="data-table data-table--roomy data-table--fit">
                    <caption class="sr-only">Ranking de cursos por promedio de puntos</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Curso</th>
                            <th scope="col" class="cell-hide-sm">Estudiantes</th>
                            <th scope="col" class="cell-hide-sm">Depósitos</th>
                            <th scope="col" class="cell-right">Pts / estudiante</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($classes as $class)
                            <tr @if ((int) $class['class_id'] === (int) $student->class_id) class="is-me" @endif>
                                <td><span class="rank-pill rank-pill--{{ $class['rank'] }}">{{ $class['rank'] }}</span></td>
                                <td>
                                    <div class="person-cell">
                                        <span class="class-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::of($class['class_name'])->replace(['°', ' '], '')->limit(3, '') }}</span>
                                        <div>
                                            <span class="cell-strong">{{ $class['class_name'] }}</span>
                                            <span class="cell-meta">{{ $class['students'] }} estudiantes</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell-figure cell-hide-sm">{{ number_format($class['students'], 0, ',', '.') }}</td>
                                <td class="cell-figure cell-hide-sm">
                                    {{ number_format((int) ($classDeposits[$class['class_id']] ?? 0), 0, ',', '.') }} depósitos
                                </td>
                                <td class="cell-right cell-figure">
                                    {{ number_format($class['avg_points'], 0, ',', '.') }} pts
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>
        </section>

        <p class="small muted">
            Puntos totales del año escolar {{ now()->year }} · tu curso ({{ $studentClass }}).
        </p>
    @endif
@endsection
