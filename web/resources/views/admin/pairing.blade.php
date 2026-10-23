@extends('layouts.app')

@section('title', 'Vinculación')
@section('heading', 'Vinculación')
@section('subtitle', 'Asocia una tarjeta o un teléfono a un estudiante')

@php
    $pending = $status['pending'];
    $lastPairing = $status['last_pairing'];
    $lastPairingAt = $lastPairing !== null ? \Illuminate\Support\Carbon::parse($lastPairing['paired_at']) : null;
    $lastPairingRecent = $lastPairingAt !== null && $lastPairingAt->gte(now()->subMinute());
    $hasPending = $pending !== null;
    $showSuccess = ! $hasPending && $lastPairingRecent;

    $pendingStudentId = $pending['student_id'] ?? null;
    $linkedStudentId = $showSuccess ? $lastPairing['student_id'] : null;
    $pendingStudent = $pendingStudentId !== null ? $students->firstWhere('id', $pendingStudentId) : null;
    $lastStudent = $linkedStudentId !== null ? $students->firstWhere('id', $linkedStudentId) : null;

    $credentialLabel = fn (?string $kind): string => $kind === 'hce' ? 'Teléfono' : 'Tarjeta NFC';
    $unlinkedCount = $students->filter(
        fn ($student) => ! $student->cards->contains(fn ($card) => $card->isActive())
    )->count();
@endphp

@section('content')
    <div
        class="pairing-layout"
        data-pairing-desk
        data-pairing-status-url="{{ route('admin.pairing.status') }}"
        data-pairing-cancel-url="{{ route('admin.pairing.cancel') }}"
        data-realtime-token-url="{{ route('realtime.token') }}"
    >
        <section class="card pairing-card" aria-labelledby="pairing-pick-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="pairing-pick-title">1. Elige al estudiante</h2>
                </div>
            </div>

            <div class="card__body pairing-card__body">
                <label class="search-field" for="pairing-search">
                    <x-icon name="search" />
                    <span class="sr-only">Buscar estudiante</span>
                    <input
                        class="input"
                        id="pairing-search"
                        type="search"
                        placeholder="Buscar estudiante"
                        autocomplete="off"
                        data-pairing-search
                    >
                </label>

                <div class="segmented pairing-filters" role="group" aria-label="Filtrar estudiantes">
                    <button class="segmented__item is-active" type="button" data-pairing-filter="unlinked" aria-pressed="true">
                        Sin vincular ({{ $unlinkedCount }})
                    </button>
                    <button class="segmented__item" type="button" data-pairing-filter="all" aria-pressed="false">
                        Todos
                    </button>
                </div>

                <div class="pairing-list" data-pairing-list>
                    @foreach ($students as $student)
                        @php
                            $activeCards = $student->cards->filter(fn ($card) => $card->isActive());
                            $hasCredential = $activeCards->isNotEmpty();
                            $isArmed = (int) $student->id === (int) $pendingStudentId;
                            $isLinkedRow = (int) $student->id === (int) $linkedStudentId;
                            $kindLabel = $activeCards->contains(fn ($card) => $card->kind === \App\Enums\CardKind::Hce)
                                ? 'teléfono'
                                : 'tarjeta';
                        @endphp

                        <div
                            class="pairing-student {{ $isArmed ? 'is-armed' : '' }} {{ $isLinkedRow ? 'is-linked' : '' }}"
                            data-pairing-student-id="{{ $student->id }}"
                            data-has-credential="{{ $hasCredential ? '1' : '0' }}"
                            data-class-name="{{ $student->schoolClass?->name ?? 'Sin curso' }}"
                            data-search="{{ mb_strtolower($student->name.' '.($student->schoolClass?->name ?? '')) }}"
                            @if ($hasCredential && ! $isArmed && ! $isLinkedRow) hidden @endif
                        >
                            <x-avatar :name="$student->name" />

                            <div class="pairing-student__main">
                                <p class="pairing-student__name">{{ $student->name }}</p>
                                <p class="pairing-student__meta">
                                    {{ $student->schoolClass?->name ?? 'Sin curso' }}
                                    · {{ $hasCredential ? 'con credencial de '.$kindLabel : 'sin credencial' }}
                                </p>
                            </div>

                            <div class="pairing-student__aside">
                                <form
                                    class="pairing-student__form"
                                    method="POST"
                                    action="{{ route('admin.pairing.arm') }}"
                                    data-pairing-arm-form
                                    @if ($isArmed || $isLinkedRow) hidden @endif
                                >
                                    @csrf
                                    <input type="hidden" name="student_id" value="{{ $student->id }}">
                                    <button class="btn btn--outline btn--sm" type="submit">Armar</button>
                                </form>

                                <span class="pairing-student__state" data-pairing-state @if (! $isArmed && ! $isLinkedRow) hidden @endif>
                                    {{ $isArmed ? 'Armado' : ($isLinkedRow ? 'Vinculado ✓' : '') }}
                                </span>
                            </div>
                        </div>
                    @endforeach

                    <p class="pairing-list__empty small muted" data-pairing-empty hidden>
                        No hay estudiantes que coincidan con la búsqueda.
                    </p>
                </div>
            </div>
        </section>

        <section class="card pairing-card pairing-desk" aria-labelledby="pairing-reader-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="pairing-reader-title">2. Acerca la credencial</h2>
                </div>
            </div>

            <div class="card__body pairing-card__body">
                <div class="pairing-state" data-pairing-success @if (! $showSuccess) hidden @endif>
                    <div class="success-art" aria-hidden="true">
                        <span class="success-art__inner"><x-icon name="check" :size="52" /></span>
                        <img class="success-art__boti" src="{{ asset('img/boti.svg') }}" alt="" width="72" height="72">
                    </div>

                    <h3 class="pairing-state__title">¡Listo! Credencial vinculada</h3>
                    <p class="pairing-state__text">
                        <strong data-pairing-student>{{ $lastPairing['student_name'] ?? '' }}</strong>
                        <span data-pairing-student-class>
                            @if ($lastStudent?->schoolClass)
                                ({{ $lastStudent->schoolClass->name }})
                            @endif
                        </span>
                        ya puede tocar cualquier estación y sumar puntos.
                    </p>

                    <dl class="pairing-meta">
                        <div class="pairing-meta__row">
                            <dt>Tipo</dt>
                            <dd data-pairing-credential>
                                {{ $credentialLabel($lastPairing['card_kind'] ?? 'physical') }}
                            </dd>
                        </div>
                        <div class="pairing-meta__row">
                            <dt>Estación</dt>
                            <dd data-pairing-station>{{ $lastPairing['reader_label'] ?? 'Sin estación' }}</dd>
                        </div>
                        <div class="pairing-meta__row">
                            <dt>Hora</dt>
                            <dd data-pairing-time>
                                {{ $lastPairingAt?->timezone(config('app.timezone'))->locale('es')->translatedFormat('j M Y · H:i') }}
                            </dd>
                        </div>
                        <div class="pairing-meta__row">
                            <dt>UID</dt>
                            <dd class="mono" data-pairing-uid>{{ $lastPairing['card_uid'] ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div class="pairing-state__actions">
                        <button class="btn btn--primary" type="button" data-pairing-dismiss>
                            Vincular otra
                        </button>
                    </div>
                </div>

                <div class="pairing-state" data-pairing-pending @if (! $hasPending) hidden @endif>
                    <div class="reader" aria-hidden="true">
                        <span class="reader__core">
                            <x-icon name="card" :size="38" />
                        </span>
                    </div>

                    <h3 class="pairing-state__title">Acerca la tarjeta o el teléfono a cualquier estación</h3>
                    <p class="pairing-state__text">
                        <strong data-pairing-pending-student>{{ $pendingStudent?->name ?? $pending['student_name'] ?? '' }}</strong>
                        @if ($pendingStudent?->schoolClass)
                            ({{ $pendingStudent->schoolClass->name }})
                        @endif
                        está armado. La próxima credencial que toque cualquier estación quedará vinculada a él.
                    </p>

                    <p class="waiting-pill" role="timer" aria-live="off">
                        <span class="waiting-pill__dot" aria-hidden="true"></span>
                        Esperando toque
                        <strong class="mono" data-pairing-countdown>
                            @if (($pending['seconds_left'] ?? null) !== null)
                                {{ sprintf('%d:%02d', intdiv((int) $pending['seconds_left'], 60), (int) $pending['seconds_left'] % 60) }}
                            @else
                                0:00
                            @endif
                        </strong>
                    </p>

                    @if (($pending['last_rejection'] ?? null) !== null)
                        <p class="pairing-rejection" role="alert">
                            <x-icon name="warning" :size="16" />
                            La credencial {{ $pending['last_rejection']['card_uid'] }} no se pudo vincular.
                        </p>
                    @endif

                    <form class="pairing-state__actions" method="POST" action="{{ route('admin.pairing.cancel') }}" data-pairing-cancel-form>
                        @csrf
                        <button class="btn btn--outline" type="submit" data-pairing-cancel>Cancelar</button>
                    </form>
                </div>

                <div class="pairing-state" data-pairing-idle @if ($hasPending || $showSuccess) hidden @endif>
                    <div class="reader" aria-hidden="true">
                        <span class="reader__core">
                            <x-icon name="card" :size="38" />
                        </span>
                    </div>

                    <h3 class="pairing-state__title">Elige un estudiante y pulsa Armar</h3>
                    <p class="pairing-state__text">
                        La ventana dura unos segundos: mientras esté abierta, la próxima credencial que toque
                        cualquier estación quedará vinculada al estudiante elegido.
                    </p>
                </div>
            </div>
        </section>
    </div>

    <div class="grid-2 pairing-history">
        <section class="card" aria-labelledby="pairing-recent-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="pairing-recent-title">Últimos emparejamientos</h2>
                    <p class="card__subtitle">Las ocho vinculaciones más recientes.</p>
                </div>
            </div>

            <div class="card__body">
                @if (count($status['recent_pairings']) === 0)
                    <x-empty
                        icon="link"
                        title="Todavía no hay emparejamientos"
                        text="Arma un estudiante y acerca su credencial a una estación."
                    />
                @else
                    <div class="pairing-history__list">
                        @foreach ($status['recent_pairings'] as $pairing)
                            @php
                                $pairedAt = $pairing['paired_at']
                                    ? \Illuminate\Support\Carbon::parse($pairing['paired_at'])
                                    : null;
                            @endphp

                            <div class="pairing-history__row">
                                <div class="pairing-history__main">
                                    <p class="pairing-history__name">{{ $pairing['student_name'] }}</p>
                                    <p class="pairing-history__meta">
                                        {{ $credentialLabel($pairing['card_kind']) }}
                                        <span class="mono">{{ $pairing['card_uid'] }}</span>
                                        · {{ $pairing['reader_label'] ?? 'Sin estación' }}
                                    </p>
                                </div>
                                <span class="chip chip--success">
                                    <span class="chip__dot" aria-hidden="true"></span>
                                    {{ $pairedAt?->timezone(config('app.timezone'))->format('d/m H:i') }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="card" aria-labelledby="pairing-credentials-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="pairing-credentials-title">Credenciales</h2>
                    <p class="card__subtitle">Revoca una credencial perdida o desempareja una activa.</p>
                </div>
            </div>

            <div class="card__body card__body--flush">
                <x-table-wrapper label="Credenciales de los estudiantes">
                    <table class="data-table data-table--fit admin-table">
                        <caption class="sr-only">Credenciales y su estado</caption>
                        <thead>
                            <tr>
                                <th scope="col">Estudiante</th>
                                <th scope="col">Credencial</th>
                                <th scope="col">Tipo</th>
                                <th scope="col">Estado</th>
                                <th scope="col" class="cell-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($students as $student)
                                @forelse ($student->cards as $card)
                                    <tr>
                                        <td data-label="Estudiante">
                                            <div class="person-cell">
                                                <x-avatar :name="$student->name" size="sm" />
                                                <div class="person-cell__body">
                                                    <span class="cell-strong">{{ $student->name }}</span>
                                                    <span class="cell-meta">{{ $student->schoolClass?->name ?? 'Sin curso' }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Credencial" class="mono">{{ $card->credential_uid }}</td>
                                        <td data-label="Tipo">{{ $credentialLabel($card->kind->value) }}</td>
                                        <td data-label="Estado">
                                            @if ($card->isActive())
                                                <span class="chip chip--success">
                                                    <span class="chip__dot" aria-hidden="true"></span>
                                                    Activa
                                                </span>
                                            @else
                                                <span class="chip chip--danger">
                                                    <span class="chip__dot" aria-hidden="true"></span>
                                                    Revocada
                                                </span>
                                            @endif
                                        </td>
                                        <td data-label="Acciones" class="cell-right">
                                            <div class="row-actions">
                                                @if ($card->isActive())
                                                    <form method="POST" action="{{ route('admin.cards.revoke', $card) }}">
                                                        @csrf
                                                        <button class="btn btn--outline btn--sm" type="submit">Revocar</button>
                                                    </form>
                                                @endif

                                                <form method="POST" action="{{ route('admin.cards.destroy', $card) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn--ghost btn--sm" type="submit">Desemparejar</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td data-label="Estudiante">
                                            <div class="person-cell">
                                                <x-avatar :name="$student->name" size="sm" />
                                                <span class="cell-strong">{{ $student->name }}</span>
                                            </div>
                                        </td>
                                        <td colspan="4" class="muted">Sin credenciales.</td>
                                    </tr>
                                @endforelse
                            @empty
                                <tr>
                                    <td colspan="5" class="muted">Todavía no hay estudiantes.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-table-wrapper>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/realtime.js') }}" defer></script>
@endpush
