@extends('layouts.app')

@section('title', 'Exportar')
@section('heading', 'Exportar')
@section('subtitle', 'Descarga reportes de tus cursos en PDF o CSV')

@php
    $fromLabel = \Illuminate\Support\Carbon::parse($from)->format('d/m/Y');
    $toLabel = \Illuminate\Support\Carbon::parse($to)->format('d/m/Y');
    $rangeLabel = $fromLabel.' – '.$toLabel;
    $scopeLabel = $classes->isEmpty()
        ? 'Sin cursos asignados'
        : ($classes->count() > 3
            ? $classes->count().' cursos en tu ámbito'
            : $classes->pluck('name')->implode(' · '));

    $maxMaterialItems = max(array_column($byMaterial, 'items')) ?: 1;
    $materialNames = [
        'plastic' => 'Plástico',
        'metal' => 'Metal',
        'glass' => 'Vidrio',
        'paper' => 'Papel',
        'other' => 'Otro',
    ];
    $materialTones = [
        'plastic' => 'blue',
        'metal' => 'coral',
        'glass' => 'turquoise',
        'paper' => 'amber',
        'other' => 'pink',
    ];
@endphp

@section('filters')
    <form method="GET" action="{{ route('teacher.export') }}" class="filters">
        <x-field name="from" label="Desde" type="date" :value="$from" icon="calendar" class="filters__field" />
        <x-field name="to" label="Hasta" type="date" :value="$to" icon="calendar" class="filters__field" />
        <button class="btn btn--outline btn--sm" type="submit">Actualizar resumen</button>

        <span class="filter-pill">
            <x-icon name="calendar" />
            {{ $rangeLabel }}
        </span>
    </form>
@endsection

@section('content')
    <div class="export-split">
        <section class="card" aria-labelledby="nuevo-reporte-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="nuevo-reporte-title">Nuevo reporte</h2>
                    <p class="card__subtitle">{{ $scopeLabel }} · {{ $rangeLabel }}</p>
                </div>
            </div>

            <div class="card__body stack">
                <div class="stack stack--tight">
                    <p class="field__label">Formato</p>

                    <a class="export-option export-option--active" href="{{ $pdfUrl }}">
                        <span class="export-option__icon" aria-hidden="true"><x-icon name="download" :size="24" /></span>
                        <span>
                            <span class="export-option__title">PDF</span>
                            <span class="export-option__text">Reporte para imprimir con resumen, gráficas y fotos.</span>
                            <span class="export-option__cta">Descargar PDF →</span>
                        </span>
                    </a>

                    <a class="export-option" href="{{ $csvUrl }}">
                        <span class="export-option__icon" aria-hidden="true"><x-icon name="chart" :size="24" /></span>
                        <span>
                            <span class="export-option__title">CSV</span>
                            <span class="export-option__text">Datos crudos de cada depósito para Excel u hojas de cálculo.</span>
                            <span class="export-option__cta">Descargar CSV →</span>
                        </span>
                    </a>
                </div>

                <div class="stack stack--tight">
                    <p class="field__label">Alcance</p>
                    <div class="cluster">
                        @forelse ($classes as $class)
                            <span class="chip chip--neutral">{{ $class->name }}</span>
                        @empty
                            <span class="chip chip--warning">Sin cursos asignados</span>
                        @endforelse
                    </div>
                    <p class="small muted">
                        El reporte incluye únicamente los cursos de tu ámbito. No puedes exportar datos de otro curso.
                    </p>
                </div>

                <div class="stack stack--tight">
                    <p class="field__label">Rangos rápidos</p>
                    <div class="cluster">
                        <a class="chip chip--success" href="{{ route('teacher.export', ['from' => now()->startOfWeek()->toDateString(), 'to' => now()->toDateString()]) }}">Esta semana</a>
                        <a class="chip chip--success" href="{{ route('teacher.export', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]) }}">Este mes</a>
                        <a class="chip chip--success" href="{{ route('teacher.export', ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]) }}">Año escolar</a>
                    </div>
                </div>

                <div class="cluster">
                    <a class="btn btn--primary" href="{{ $pdfUrl }}">
                        <x-icon name="download" />
                        Generar PDF
                    </a>
                    <a class="btn btn--outline" href="{{ $csvUrl }}">
                        <x-icon name="download" />
                        Descargar CSV
                    </a>
                </div>

                <p class="small muted">
                    Las fotos se borran automáticamente a los 30 días; los depósitos y los puntos se conservan.
                </p>
            </div>
        </section>

        <aside class="stack" aria-label="Vista previa del reporte">
            <section class="card" aria-labelledby="preview-title">
                <div class="card__header">
                    <h2 class="card__title" id="preview-title">Vista previa</h2>
                </div>

                <div class="card__body">
                    <div class="preview-sheet" aria-label="Resumen que incluirá el reporte">
                        <div class="preview-sheet__logo">
                            <img src="{{ asset('img/boti.svg') }}" alt="" width="22" height="22" aria-hidden="true">
                            Reporte de reciclaje
                        </div>
                        <p class="preview-sheet__meta">{{ $scopeLabel }} · {{ $rangeLabel }}</p>

                        <div class="preview-sheet__stats">
                            <div class="preview-sheet__stat">
                                <strong>{{ number_format($summary['items'], 0, ',', '.') }}</strong>
                                <span>depósitos</span>
                            </div>
                            <div class="preview-sheet__stat">
                                <strong>{{ number_format($summary['points'], 0, ',', '.') }}</strong>
                                <span>puntos</span>
                            </div>
                            <div class="preview-sheet__stat">
                                <strong>{{ $summary['recyclablePct'] }} %</strong>
                                <span>reciclable</span>
                            </div>
                        </div>

                        <div class="preview-sheet__bars">
                            @foreach (['plastic', 'metal', 'glass', 'paper', 'other'] as $key)
                                @php $row = $byMaterial[$key] ?? ['items' => 0]; @endphp
                                <div class="preview-sheet__bar">
                                    <span>{{ $materialNames[$key] }}</span>
                                    <i
                                        class="bar__fill--{{ $materialTones[$key] }}"
                                        style="width: {{ $row['items'] > 0 ? max(3, round($row['items'] / $maxMaterialItems * 100)) : 0 }}%"
                                    ></i>
                                </div>
                            @endforeach
                        </div>

                        @for ($i = 0; $i < 6; $i++)
                            <div class="preview-sheet__rule"></div>
                        @endfor
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="alcance-title">
                <div class="card__header">
                    <h2 class="card__title" id="alcance-title">Resumen del rango</h2>
                </div>

                <div class="card__body">
                    <div class="stat-list">
                        <div class="stat-list__row">
                            <span class="stat-list__label">Depósitos</span>
                            <span class="stat-list__value">{{ number_format($summary['items'], 0, ',', '.') }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Puntos</span>
                            <span class="stat-list__value">{{ number_format($summary['points'], 0, ',', '.') }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Estudiantes</span>
                            <span class="stat-list__value">{{ $summary['activeStudents'] }} de {{ $summary['totalStudents'] }}</span>
                        </div>
                        <div class="stat-list__row">
                            <span class="stat-list__label">Reciclable</span>
                            <span class="stat-list__value">{{ $summary['recyclablePct'] }} %</span>
                        </div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
@endsection
