@extends('layouts.app')

@section('title', 'Ajustes')
@section('heading', 'Ajustes')
@section('subtitle', 'Reglas de puntos, privacidad de las fotos y cuentas')

@php
    $materials = [
        'plastic' => 'Plástico',
        'metal' => 'Metal',
        'glass' => 'Vidrio',
        'paper' => 'Papel',
        'other' => 'Otro',
    ];
    $defaults = config('ecostation.points');
    $errorFor = function (string $key) use ($errors): ?string {
        return $errors->first($key) ?: $errors->first('settings.'.$key) ?: null;
    };
    $retention = (int) $values['photos.retention_days'];
    $retentionPresets = [7, 15, 30, 60, 90];
    $oldSettings = old('settings', []);
@endphp

@section('content')
    <form class="settings-form" method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')

        <div class="settings-grid">
            <section class="card" aria-labelledby="points-title">
                <div class="card__header">
                    <div>
                        <h2 class="card__title" id="points-title">Puntos por material</h2>
                        <p class="card__subtitle">
                            Se aplican a los depósitos nuevos. Los puntos ya otorgados no cambian.
                        </p>
                    </div>
                </div>

                <div class="card__body">
                    <div class="points-settings">
                        @foreach ($materials as $material => $label)
                            @php
                                $key = 'points.'.$material;
                                $inputId = 'setting-points-'.$material;
                                $error = $errorFor($key);
                            @endphp

                            <div class="points-setting">
                                <div class="points-setting__label">
                                    <x-material-badge :material="$material" with-value />
                                    <label class="sr-only" for="{{ $inputId }}">{{ $label }}, puntos por depósito</label>
                                </div>

                                <input
                                    class="input points-setting__input"
                                    id="{{ $inputId }}"
                                    name="settings[{{ $key }}]"
                                    type="number"
                                    min="0"
                                    max="1000"
                                    step="1"
                                    inputmode="numeric"
                                    value="{{ $oldSettings[$key] ?? $values[$key] }}"
                                    required
                                    @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
                                >

                                <span class="points-setting__unit">
                                    {{ $material === 'other' ? 'pts · no reciclable' : 'pts por depósito' }}
                                </span>

                                @if ($error)
                                    <p class="field__error points-setting__error" id="{{ $inputId }}-error">
                                        <x-icon name="error" />
                                        <span>{{ $error }}</span>
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <p class="small muted section-note">
                        Valores por defecto:
                        {{ $defaults['plastic'] }} · {{ $defaults['metal'] }} · {{ $defaults['glass'] }} ·
                        {{ $defaults['paper'] }} · {{ $defaults['other'] }}.
                    </p>
                </div>
            </section>

            <div class="settings-stack">
                <section class="card" aria-labelledby="photos-title">
                    <div class="card__header">
                        <div>
                            <h2 class="card__title" id="photos-title">Fotos de los depósitos</h2>
                            <p class="card__subtitle">Privacidad de las imágenes que captura la estación.</p>
                        </div>
                    </div>

                    <div class="card__body">
                        <div class="retention-row">
                            <div class="field">
                                <label class="field__label" for="setting-photos-retention">Conservar fotos durante</label>
                                <div class="retention-row__control">
                                    <input
                                        class="input retention-row__input"
                                        id="setting-photos-retention"
                                        name="settings[photos.retention_days]"
                                        type="number"
                                        min="1"
                                        max="365"
                                        step="1"
                                        inputmode="numeric"
                                        value="{{ $oldSettings['photos.retention_days'] ?? $retention }}"
                                        required
                                        data-retention-input
                                        @if ($errorFor('photos.retention_days')) aria-invalid="true" @endif
                                    >
                                    <span class="retention-row__unit">días</span>
                                </div>
                                @if ($errorFor('photos.retention_days'))
                                    <p class="field__error">
                                        <x-icon name="error" />
                                        <span>{{ $errorFor('photos.retention_days') }}</span>
                                    </p>
                                @endif
                            </div>
                        </div>

                        <div class="preset-chips" role="group" aria-label="Plazos sugeridos">
                            @foreach ($retentionPresets as $preset)
                                <button
                                    class="preset-chip {{ $retention === $preset ? 'is-active' : '' }}"
                                    type="button"
                                    data-retention-preset="{{ $preset }}"
                                    aria-pressed="{{ $retention === $preset ? 'true' : 'false' }}"
                                >
                                    {{ $preset }} días
                                </button>
                            @endforeach
                        </div>

                        <p class="field__help section-note">
                            Pasado ese plazo la foto se borra automáticamente. El depósito, su clasificación y
                            los puntos se conservan.
                        </p>

                        <div class="notice notice--tight">
                            <x-icon name="camera" />
                            <p>
                                Las fotos se envían a DeepSeek para clasificar el material. Informa a las
                                familias en la política de datos del colegio.
                            </p>
                        </div>
                    </div>
                </section>

                <section class="card" aria-labelledby="pairing-window-title">
                    <div class="card__header">
                        <div>
                            <h2 class="card__title" id="pairing-window-title">Ventana de vinculación</h2>
                            <p class="card__subtitle">Cuánto tiempo espera la estación una credencial nueva.</p>
                        </div>
                    </div>

                    <div class="card__body">
                        <div class="field">
                            <label class="field__label" for="setting-pairing-window">Duración de la ventana</label>
                            <div class="retention-row__control">
                                <input
                                    class="input retention-row__input"
                                    id="setting-pairing-window"
                                    name="settings[pairing.window_seconds]"
                                    type="number"
                                    min="10"
                                    max="600"
                                    step="1"
                                    inputmode="numeric"
                                    value="{{ $oldSettings['pairing.window_seconds'] ?? $values['pairing.window_seconds'] }}"
                                    required
                                    @if ($errorFor('pairing.window_seconds')) aria-invalid="true" @endif
                                >
                                <span class="retention-row__unit">segundos</span>
                            </div>
                            <p class="field__help">Entre 10 y 600 segundos. El escritorio muestra la cuenta regresiva.</p>
                            @if ($errorFor('pairing.window_seconds'))
                                <p class="field__error">
                                    <x-icon name="error" />
                                    <span>{{ $errorFor('pairing.window_seconds') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="card" aria-labelledby="camera-title">
                    <div class="card__header">
                        <div>
                            <h2 class="card__title" id="camera-title">Cámara de la estación</h2>
                            <p class="card__subtitle">Preview en vivo para docentes y administración.</p>
                        </div>
                    </div>

                    <div class="card__body">
                        <div class="field">
                            <label class="field__label" for="setting-camera-url">URL del visualizador</label>
                            <div class="field__control">
                                <x-icon name="station" class="field__icon" />
                                <input
                                    class="input"
                                    id="setting-camera-url"
                                    name="settings[camera.preview_url]"
                                    type="text"
                                    value="{{ $oldSettings['camera.preview_url'] ?? $values['camera.preview_url'] }}"
                                    placeholder="http://192.168.40.192"
                                    inputmode="url"
                                    @if ($errorFor('camera.preview_url')) aria-invalid="true" @endif
                                >
                            </div>
                            <p class="field__help">
                                IP de la ESP32-CAM en la LAN, sin puerto (la estación sirve en el 80).
                                Se usa en <code>/camera</code>. Déjala vacía para ocultar la vista.
                            </p>
                            @if ($errorFor('camera.preview_url'))
                                <p class="field__error">
                                    <x-icon name="error" />
                                    <span>{{ $errorFor('camera.preview_url') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="card" aria-labelledby="accounts-title">
                    <div class="card__header">
                        <div>
                            <h2 class="card__title" id="accounts-title">Cuentas de estudiantes</h2>
                            <p class="card__subtitle">Cómo se generan el correo y la contraseña iniciales.</p>
                        </div>
                    </div>

                    <div class="card__body">
                        <div class="field">
                            <label class="field__label" for="setting-student-domain">Dominio del correo</label>
                            <div class="field__control">
                                <x-icon name="mail" class="field__icon" />
                                <input
                                    class="input"
                                    id="setting-student-domain"
                                    name="settings[accounts.student_email_domain]"
                                    type="text"
                                    value="{{ $oldSettings['accounts.student_email_domain'] ?? $values['accounts.student_email_domain'] }}"
                                    placeholder="ecostation.test"
                                    required
                                    @if ($errorFor('accounts.student_email_domain')) aria-invalid="true" @endif
                                >
                            </div>
                            <p class="field__help">
                                Los correos se generan como <strong>nombre@dominio</strong>.
                            </p>
                            @if ($errorFor('accounts.student_email_domain'))
                                <p class="field__error">
                                    <x-icon name="error" />
                                    <span>{{ $errorFor('accounts.student_email_domain') }}</span>
                                </p>
                            @endif
                        </div>

                        <div class="field">
                            <label class="field__label" for="setting-student-password">Contraseña inicial</label>
                            <div class="field__control">
                                <x-icon name="lock" class="field__icon" />
                                <input
                                    class="input"
                                    id="setting-student-password"
                                    name="settings[accounts.student_initial_password]"
                                    type="password"
                                    value=""
                                    placeholder="Aleatoria por estudiante"
                                    autocomplete="new-password"
                                    @if ($errorFor('accounts.student_initial_password')) aria-invalid="true" @endif
                                >
                            </div>
                            <p class="field__help">
                                Déjala vacía para generar una contraseña distinta por estudiante. Si escribes una,
                                todos los estudiantes nuevos compartirán esa clave temporal.
                            </p>
                            @if ($errorFor('accounts.student_initial_password'))
                                <p class="field__error">
                                    <x-icon name="error" />
                                    <span>{{ $errorFor('accounts.student_initial_password') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="save-bar">
            <p class="save-bar__text">
                Los cambios se aplican a los próximos depósitos y a las próximas cuentas creadas.
            </p>
            <div class="save-bar__actions">
                <button class="btn btn--ghost save-bar__dismiss" type="reset">Descartar</button>
                <button class="btn btn--primary" type="submit">Guardar cambios</button>
            </div>
        </div>
    </form>
@endsection
