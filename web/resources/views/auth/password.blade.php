@extends('layouts.app')

@section('title', 'Cambiar contraseña')

@section('content')
    <h1>Define una nueva contraseña</h1>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        @method('PUT')

        <p>
            <label for="current_password">Contraseña actual</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
        </p>

        <p>
            <label for="password">Nueva contraseña</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
        </p>

        <p>
            <label for="password_confirmation">Confirma la nueva contraseña</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </p>

        <button type="submit">Guardar contraseña</button>
    </form>
@endsection
