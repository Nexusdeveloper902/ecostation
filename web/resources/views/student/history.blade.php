@extends('layouts.app')

@section('title', 'Historial')
@section('heading', 'Historial')
@section('subtitle', 'Todos tus depósitos y los puntos que ganaste')

@php
    $materialNames = [
        'plastic' => 'plástico',
        'metal' => 'metal',
        'glass' => 'vidrio',
        'paper' => 'papel',
        'other' => 'otro',
    ];

    $titleFor = function ($deposit) use ($materialNames) {
        $material = (string) $deposit->material_class;

        return ($deposit->is_bottle ? 'Botella de ' : 'Depósito de ').($materialNames[$material] ?? $material);
    };
@endphp

@section('filters')
    <form method="GET" action="{{ route('student.history') }}" class="filters">
        <x-select
            name="month"
            label="Mes"
            :options="$months"
            :selected="$selectedMonth"
            icon="calendar"
            class="filters__field"
        />

        <div class="field">
            <span class="field__label">Material</span>
            <div class="cluster cluster--scroll">
                <a
                    class="chip chip--neutral {{ $materialFilter === '' ? 'chip--active' : '' }}"
                    href="{{ route('student.history', ['month' => $selectedMonth]) }}"
                    @if ($materialFilter === '') aria-current="true" @endif
                >Todos</a>

                @foreach (['azul' => 'Plástico', 'coral' => 'Metal', 'turquesa' => 'Vidrio', 'ambar' => 'Papel', 'rosa' => 'Otro'] as $alias => $label)
                    <a
                        class="chip chip--{{ ['azul' => 'blue', 'coral' => 'coral', 'turquesa' => 'turquoise', 'ambar' => 'amber', 'rosa' => 'pink'][$alias] }} {{ $materialFilter === $alias ? 'chip--active' : '' }}"
                        href="{{ route('student.history', ['month' => $selectedMonth, 'material' => $alias]) }}"
                        @if ($materialFilter === $alias) aria-current="true" @endif
                    >
                        <span class="chip__dot" aria-hidden="true"></span>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <button class="btn btn--outline btn--sm" type="submit">Aplicar</button>

        <div class="toolbar__group filters__summary">
            <span class="small muted">{{ ucfirst($monthLabel) }}:</span>
            <span class="strong">{{ number_format($monthItems, 0, ',', '.') }} depósitos</span>
            <x-points-badge :points="$monthPoints" />
        </div>
    </form>
@endsection

@section('content')
    <section class="card" aria-label="Depósitos de {{ $monthLabel }}">
        @if ($deposits->isEmpty())
            <div class="card__body">
                <x-empty
                    icon="clock"
                    title="Sin depósitos en este periodo"
                    text="Prueba con otro mes o quita el filtro de material. Tus depósitos aparecen aquí apenas la estación los clasifica."
                >
                    <a class="btn btn--outline btn--sm" href="{{ route('student.dashboard') }}">Volver al inicio</a>
                </x-empty>
            </div>
        @else
            <x-table-wrapper class="history-table" label="Historial de depósitos">
                <table class="data-table data-table--roomy">
                    <caption class="sr-only">Depósitos de {{ $monthLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Material</th>
                            <th scope="col">Objeto</th>
                            <th scope="col">Estación</th>
                            <th scope="col" class="cell-right">Puntos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deposits as $deposit)
                            @php
                                $occurred = \Illuminate\Support\Carbon::parse($deposit->occurred_at)->locale('es');
                                $confidence = (int) round((float) $deposit->confidence * 100);
                            @endphp

                            <tr>
                                <td class="nowrap">
                                    <span class="mono">{{ $occurred->translatedFormat('j M') }}</span>
                                    <span class="muted"> · {{ $occurred->format('H:i') }}</span>
                                </td>
                                <td>
                                    <x-material-badge :material="$deposit->material_class" :with-value="true" />
                                </td>
                                <td>
                                    <span class="cell-strong">{{ $titleFor($deposit) }}</span>
                                    <span class="cell-meta">
                                        {{ $deposit->is_recyclable ? 'Clasificado' : 'No reciclable' }} · confianza {{ $confidence }} %
                                    </span>
                                </td>
                                <td class="muted">{{ $deposit->reader_label ?? '—' }}</td>
                                <td class="cell-right">
                                    <x-points-badge :points="$deposit->points_awarded" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-wrapper>

            <div class="history-cards" aria-label="Historial de depósitos">
                @foreach ($deposits->getCollection()->groupBy(fn ($deposit) => \Illuminate\Support\Carbon::parse($deposit->occurred_at)->toDateString()) as $day => $rows)
                    @php
                        $date = \Illuminate\Support\Carbon::parse($day)->locale('es');
                        $dayLabel = $date->isToday()
                            ? 'Hoy · '.$date->translatedFormat('l j')
                            : ($date->isYesterday() ? 'Ayer · '.$date->translatedFormat('l j') : ucfirst($date->translatedFormat('l j')));
                    @endphp

                    <section class="history-day">
                        <h2 class="history-day__title">{{ $dayLabel }}</h2>

                        @foreach ($rows as $deposit)
                            @php
                                $occurred = \Illuminate\Support\Carbon::parse($deposit->occurred_at)->locale('es');
                            @endphp

                            <article class="history-card">
                                <div class="history-card__main">
                                    <x-material-badge :material="$deposit->material_class" :with-value="true" />
                                    <p class="history-card__meta">
                                        {{ $occurred->format('H:i') }} · {{ $titleFor($deposit) }}
                                    </p>
                                </div>

                                <x-points-badge :points="$deposit->points_awarded" />
                            </article>
                        @endforeach
                    </section>
                @endforeach
            </div>

            <x-pagination :paginator="$deposits" label="depósitos" load-more />
        @endif
    </section>
@endsection
