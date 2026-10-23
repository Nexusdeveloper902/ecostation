@extends('layouts.auth')

@section('title', 'Cambiar contraseña')

@section('content')
    <h1 class="auth__title">Crea tu contraseña</h1>
    <p class="auth__subtitle">Define una nueva contraseña para tu cuenta.</p>

    @if (auth()->user()?->must_change_password)
        <div class="alert alert--warning auth__notice">
            <x-icon name="warning" />
            <p>Es tu primer ingreso. Por seguridad, cambia la contraseña temporal antes de continuar.</p>
        </div>
    @else
        <div class="alert alert--info auth__notice">
            <x-icon name="lock" />
            <p>Tu contraseña protege tus puntos. Elige una que solo tú conozcas.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" data-password-form>
        @csrf
        @method('PUT')

        <input type="hidden" name="username" value="{{ auth()->user()?->email }}" autocomplete="username">

        <div class="stack">
            <x-field
                name="current_password"
                label="Contraseña actual"
                type="password"
                icon="lock"
                autocomplete="current-password"
                required
                autofocus
            />

            <x-field
                name="password"
                label="Contraseña nueva"
                type="password"
                icon="lock"
                autocomplete="new-password"
                help="Mínimo 8 caracteres. No puede ser la contraseña inicial."
                required
            />

            <x-field
                name="password_confirmation"
                label="Confirmar contraseña"
                type="password"
                icon="lock"
                autocomplete="new-password"
                required
            />

            <ul class="auth__rules" aria-label="Requisitos de la contraseña">
                <li data-rule="length">
                    <x-icon name="check" class="icon--muted" data-rule-icon />
                    <span>Mínimo 8 caracteres</span>
                </li>
                <li data-rule="mix">
                    <x-icon name="check" class="icon--muted" data-rule-icon />
                    <span>Al menos una letra y un número</span>
                </li>
                <li data-rule="match">
                    <x-icon name="check" class="icon--muted" data-rule-icon />
                    <span>Las dos contraseñas coinciden</span>
                </li>
            </ul>

            <button class="btn btn--primary btn--block btn--lg" type="submit">Guardar y continuar</button>
        </div>
    </form>
@endsection
