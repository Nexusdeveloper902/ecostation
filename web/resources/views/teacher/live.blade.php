@extends('layouts.app')

@section('title', 'Feed en vivo')

@section('content')
    <div data-realtime-token-url="{{ $realtimeTokenUrl }}">
        <h1>Feed en vivo</h1>

        <p>Los últimos taps registrados en tu ámbito.</p>

        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Estudiante</th>
                    <th>Curso</th>
                    <th>Estación</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent as $event)
                    <tr>
                        <td>{{ $event['date'] }}</td>
                        <td>{{ $event['time'] }}</td>
                        <td>{{ $event['student_name'] }}</td>
                        <td>{{ $event['class_name'] ?? 'Sin curso' }}</td>
                        <td>{{ $event['reader_label'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Todavía no hay taps.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <p><a href="{{ route('teacher.dashboard') }}">Volver al panel</a></p>
    </div>
@endsection
