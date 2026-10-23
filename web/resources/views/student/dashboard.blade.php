@extends('layouts.app')

@section('title', 'Mi panel')
@section('heading')
    ¡Hola, {{ $student->firstName() }}!
@endsection

@php
    $now = \Illuminate\Support\Carbon::now()->locale('es');
    $classLine = $student->schoolClass?->name ?? 'Sin curso';
@endphp

@section('subtitle')
    {{ ucfirst($now->translatedFormat('l j \d\e F')) }} · {{ $classLine }}
@endsection

@section('content')
    <div class="dashboard-top">
        <section class="hero" aria-labelledby="saldo-title">
            <div>
                <p class="hero__label" id="saldo-title">Tu saldo</p>
                <p class="hero__balance">
                    {{ number_format($balance, 0, ',', '.') }}
                    <span class="hero__unit">pts</span>
                </p>
            </div>

            <div class="hero__meta">
                @if ($weekPoints > 0)
                    <span class="chip chip--sun">
                        +{{ number_format($weekPoints, 0, ',', '.') }} pts esta semana
                    </span>
                @endif

                <span class="hero__rank">
                    Puesto {{ number_format($rank, 0, ',', '.') }} de
                    {{ number_format($studentsTotal, 0, ',', '.') }} en {{ $classLine }}
                </span>
            </div>

            <img class="hero__boti" src="{{ asset('img/boti.svg') }}" alt="" width="120" height="120" aria-hidden="true">
        </section>

        <div class="kpi-stack">
            <x-kpi
                label="Depósitos este año"
                :value="number_format($yearItems, 0, ',', '.')"
                :hint="$yearSince ? 'Desde el '.$yearSince->copy()->locale('es')->translatedFormat('j \d\e F') : null"
            />

            <x-kpi
                label="Depósitos este mes"
                :value="number_format($monthItems, 0, ',', '.')"
                :hint="$monthDelta === 0
                    ? 'Igual que el mes anterior'
                    : ($monthDelta > 0
                        ? '+'.number_format($monthDelta, 0, ',', '.').' vs. el mes anterior'
                        : number_format($monthDelta, 0, ',', '.').' vs. el mes anterior')"
                :hint-tone="$monthDelta > 0 ? 'up' : null"
            />
        </div>
    </div>

    <div class="dashboard-grid">
        <section class="card" aria-labelledby="recientes-title">
            <div class="card__header">
                <h2 class="card__title" id="recientes-title">Últimos depósitos</h2>
                <a class="card__link" href="{{ route('student.history') }}">Ver historial</a>
            </div>

            <div class="card__body">
                @if ($recent->isEmpty())
                    <x-empty
                        icon="bottle"
                        title="Todavía no hay depósitos"
                        text="Cuando toques tu carné en una estación, el primer depósito aparecerá aquí."
                    >
                        <a class="btn btn--outline btn--sm" href="{{ route('student.leaderboard') }}">Ver ranking</a>
                    </x-empty>
                @else
                    <div class="deposit-list">
                        @foreach ($recent as $deposit)
                            @php
                                $occurred = \Illuminate\Support\Carbon::parse($deposit->occurred_at)->locale('es');
                                $material = (string) $deposit->material_class;
                                $materialName = ['plastic' => 'plástico', 'metal' => 'metal', 'glass' => 'vidrio', 'paper' => 'papel', 'other' => 'otro'][$material] ?? $material;
                                $title = $deposit->is_bottle ? 'Botella de '.$materialName : 'Depósito de '.$materialName;

                                $day = $occurred->isToday()
                                    ? 'Hoy'
                                    : ($occurred->isYesterday() ? 'Ayer' : $occurred->translatedFormat('j M'));

                                $meta = $day.' · '.$occurred->format('H:i');
                                if ($deposit->reader_label) {
                                    $meta .= ' · '.$deposit->reader_label;
                                }
                            @endphp

                            <article class="deposit-row deposit-row--compact">
                                <x-material-badge :material="$material" :with-value="true" />

                                <div class="deposit-row__main">
                                    <p class="deposit-row__title">{{ $title }}</p>
                                    <p class="deposit-row__meta">{{ $meta }}</p>
                                </div>

                                <x-points-badge :points="$deposit->points_awarded" />
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="card" aria-labelledby="materiales-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="materiales-title">Por material</h2>
                    <p class="card__subtitle">Depósitos de este año, por tipo</p>
                </div>
            </div>

            <div class="card__body">
                @include('partials.student.material-bars', ['byMaterial' => $byMaterial])
            </div>
        </section>
    </div>

    <section class="card" aria-label="Cómo reciclar">
        <div class="steps">
            <div class="step">
                <span class="step__art" aria-hidden="true"><x-icon name="card" :size="24" /></span>
                <div>
                    <p class="step__title">1. Toca</p>
                    <p class="step__text">Acerca tu carné o tu teléfono al lector de la estación.</p>
                </div>
            </div>

            <div class="step">
                <span class="step__art" aria-hidden="true"><x-icon name="leaf" :size="24" /></span>
                <div>
                    <p class="step__title">2. Deposita</p>
                    <p class="step__text">Suelta la botella o la lata en la boca de la estación. No tienes que esperar.</p>
                </div>
            </div>

            <div class="step">
                <span class="step__art" aria-hidden="true"><x-icon name="trophy" :size="24" /></span>
                <div>
                    <p class="step__title">3. Suma</p>
                    <p class="step__text">La foto se clasifica sola y los puntos llegan a tu cuenta en segundos.</p>
                </div>
            </div>
        </div>
    </section>
@endsection
