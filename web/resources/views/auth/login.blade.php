@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
    <h1>Iniciar sesión</h1>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <p>
            <label for="email">Correo electrónico</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </p>

        <p>
            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </p>

        <p>
            <label>
                <input type="checkbox" name="remember" value="1">
                Recordarme
            </label>
        </p>

        <button type="submit">Entrar</button>
    </form>
@endsection
