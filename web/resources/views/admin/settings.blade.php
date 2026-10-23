@extends('layouts.app')

@section('title', 'Configuración')

@section('content')
    <h1>Configuración</h1>

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')

        @foreach ($values as $key => $value)
            <p>
                <label for="setting-{{ $key }}">{{ $key }}</label>
                <input
                    id="setting-{{ $key }}"
                    name="settings[{{ $key }}]"
                    type="{{ is_int($value) ? 'number' : 'text' }}"
                    value="{{ $value }}"
                    @if (is_int($value)) step="1" @endif
                >
            </p>
        @endforeach

        <button type="submit">Guardar configuración</button>
    </form>
@endsection
