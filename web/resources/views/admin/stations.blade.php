@extends('layouts.app')

@section('title', 'Estaciones')

@section('content')
    <h1>Estaciones</h1>

    @if (session('api_key'))
        <p role="status">
            Clave de la estación (se muestra una sola vez): <strong>{{ session('api_key') }}</strong>
        </p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Huella de la clave</th>
                <th>Último evento</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stations as $station)
                <tr>
                    <td>{{ $station['label'] }}</td>
                    <td>{{ $station['fingerprint'] }}</td>
                    <td>{{ $station['last_event_at'] ?? 'Sin eventos' }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.stations.rotate', $station['id']) }}">
                            @csrf
                            <button type="submit">Rotar clave</button>
                        </form>

                        @if ($station['last_event_at'] !== null)
                            <p>Con historial: rota la clave en lugar de eliminar la estación.</p>
                        @else
                            <form method="POST" action="{{ route('admin.stations.destroy', $station['id']) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit">Eliminar</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Todavía no hay estaciones.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Crear estación</h2>

    <form method="POST" action="{{ route('admin.stations.store') }}">
        @csrf

        <p>
            <label for="station-label">Nombre</label>
            <input id="station-label" name="label" type="text" required>
        </p>

        <button type="submit">Crear estación</button>
    </form>
@endsection
