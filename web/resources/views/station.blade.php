@extends('layouts.station')

@section('title', 'Cuenta de estación')

@section('content')
    <h1 class="station__title">Cuenta de estación</h1>
    <p class="station__lead">
        Esta cuenta es para la aplicación del altavoz de la estación; no tiene panel web.
        Desde aquí solo puedes cerrar la sesión del dispositivo.
    </p>

    <ol class="station__steps">
        <li class="station__step">
            <x-icon name="station" />
            <div>
                <strong>Abre EcoStation en el altavoz</strong>
                <span>La app del parlante usa esta misma cuenta para identificarse.</span>
            </div>
        </li>
        <li class="station__step">
            <x-icon name="wifi" />
            <div>
                <strong>Conecta el altavoz a la red del colegio</strong>
                <span>La estación se descubre sola en la red local.</span>
            </div>
        </li>
        <li class="station__step">
            <x-icon name="broadcast" />
            <div>
                <strong>Deja la estación en modo parlante</strong>
                <span>Los toques de los estudiantes llegan a tu panel docente en vivo.</span>
            </div>
        </li>
    </ol>

    <div class="station__actions">
        <a class="btn btn--outline" href="{{ route('password.change') }}">
            <x-icon name="lock" />
            Cambiar contraseña
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn--primary" type="submit">
                <x-icon name="logout" />
                Cerrar sesión
            </button>
        </form>
    </div>

    <p class="station__account">Sesión iniciada como {{ auth()->user()?->email }}</p>
@endsection
