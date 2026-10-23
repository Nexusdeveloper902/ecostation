@extends('layouts.auth')

@section('title', 'Iniciar sesión')

@section('content')
    <h1 class="auth__title">Inicia sesión</h1>
    <p class="auth__subtitle">Usa el correo y la contraseña que te dio el colegio.</p>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="stack">
            <x-field
                name="email"
                label="Correo institucional"
                type="email"
                icon="mail"
                autocomplete="username"
                placeholder="valentina.gomez@losandes.edu.co"
                required
                autofocus
            />

            <x-field
                name="password"
                label="Contraseña"
                type="password"
                icon="lock"
                autocomplete="current-password"
                required
            />

            <button class="btn btn--primary btn--block btn--lg" type="submit">Entrar</button>
        </div>
    </form>

    <p class="auth__help">
        ¿Olvidaste tu contraseña? Pídele a tu docente o a coordinación que la restablezca.
    </p>
@endsection
