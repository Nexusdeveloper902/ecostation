@extends('layouts.app')

@section('title')
    Estudiante {{ $student->name }}
@endsection
@section('heading')
    {{ $student->name }}
@endsection
@section('subtitle')
    {{ $history['student']['class_name'] ?? 'Sin curso' }} · últimos 30 días
@endsection

@section('actions')
    <a class="btn btn--outline btn--sm" href="{{ route('teacher.student.export.csv', $student) }}">
        <x-icon name="download" />
        CSV
    </a>
    <a class="btn btn--outline btn--sm" href="{{ route('teacher.student.export.pdf', $student) }}">
        <x-icon name="download" />
        PDF
    </a>
@endsection

@section('content')
    <div class="kpi-grid">
        <x-kpi
            label="Puntos en 30 días"
            :value="number_format($history['totals']['points'], 0, ',', '.')"
            :hint="'Saldo total: '.number_format($balance, 0, ',', '.').' pts'"
        />
        <x-kpi
            label="Depósitos"
            :value="number_format($history['totals']['items'], 0, ',', '.')"
            :hint="$withPhoto.' con foto disponible'"
        />
        <x-kpi
            label="Reciclable"
            :value="$recyclablePct.' %'"
            :hint="(100 - $recyclablePct).' % clasificado como otro'"
        />
        <x-kpi
            label="Curso"
            :value="$history['student']['class_name'] ?? 'Sin curso'"
            text
            hint="Datos acotados a tus cursos"
        />
    </div>

    <section class="card" aria-labelledby="depositos-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="depositos-title">Depósitos de los últimos 30 días</h2>
                <p class="card__subtitle">Foto tomada por la estación, clasificación y confianza del modelo.</p>
            </div>
            <a class="card__link" href="{{ route('teacher.export') }}">Exportar reporte</a>
        </div>

        <div class="card__body">
            @if (count($history['deposits']) === 0)
                <x-empty
                    icon="bottle"
                    title="Sin depósitos en los últimos 30 días"
                    text="Cuando este estudiante use una estación, el depósito aparecerá aquí con su foto y sus puntos."
                />
            @else
                <div class="deposit-list">
                    @foreach ($history['deposits'] as $deposit)
                        @php
                            $material = (string) $deposit['material'];
                            $materialName = ['plastic' => 'plástico', 'metal' => 'metal', 'glass' => 'vidrio', 'paper' => 'papel', 'other' => 'otro'][$material] ?? $material;
                        @endphp

                        <article class="deposit-row deposit-row--photo">
                            @if ($deposit['has_photo'])
                                <a
                                    class="deposit-row__thumb"
                                    href="{{ route('deposits.photo', $deposit['deposit_id']) }}"
                                    target="_blank"
                                    rel="noopener"
                                    aria-label="Ver la foto del depósito de {{ $materialName }} del {{ $deposit['date'] }}"
                                >
                                    <img
                                        src="{{ route('deposits.photo', $deposit['deposit_id']) }}"
                                        alt=""
                                        width="48"
                                        height="48"
                                        loading="lazy"
                                    >
                                </a>
                            @else
                                <span class="deposit-row__thumb" aria-hidden="true">
                                    <x-icon name="camera" />
                                </span>
                            @endif

                            <div class="deposit-row__main">
                                <x-material-badge :material="$material" :with-value="true" />
                                <p class="deposit-row__title">
                                    {{ $deposit['is_bottle'] ?? false ? 'Botella de '.$materialName : 'Depósito de '.$materialName }}
                                </p>
                                <p class="deposit-row__meta">
                                    {{ \Illuminate\Support\Carbon::parse($deposit['date'])->locale('es')->translatedFormat('j M') }}
                                    · {{ $deposit['time'] }}
                                    @if ($deposit['reader']) · {{ $deposit['reader'] }} @endif
                                    · {{ $deposit['is_recyclable'] ? 'Clasificado' : 'No reciclable' }}
                                </p>
                            </div>

                            <div class="deposit-row__aside">
                                <x-points-badge :points="$deposit['points']" />
                            </div>
                        </article>
                    @endforeach
                </div>

                <p class="small muted section-note">
                    Las fotos se conservan 30 días. Los depósitos y los puntos permanecen aunque la foto se borre.
                </p>
            @endif
        </div>
    </section>

    <p class="small">
        <a href="{{ route('teacher.student.export.csv', $student) }}">Descargar CSV del estudiante</a>
        ·
        <a href="{{ route('teacher.student.export.pdf', $student) }}">Descargar PDF del estudiante</a>
        ·
        <a href="{{ route('teacher.dashboard') }}">Volver al resumen</a>
    </p>
@endsection
