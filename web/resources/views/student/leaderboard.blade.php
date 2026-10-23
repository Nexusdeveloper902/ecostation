@extends('layouts.app')

@section('title', 'Clasificación')

@section('content')
    <h1>Clasificación</h1>

    <dl>
        <dt>Mi posición</dt>
        <dd>{{ $me['rank'] ?? 'Sin clasificar' }}</dd>

        <dt>Mis puntos</dt>
        <dd>{{ $me['points'] }}</dd>
    </dl>

    <h2>Estudiantes</h2>

    <table>
        <thead>
            <tr>
                <th>Posición</th>
                <th>Estudiante</th>
                <th>Curso</th>
                <th>Puntos</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($top as $entry)
                <tr>
                    <td>{{ $entry['rank'] }}</td>
                    <td>{{ $entry['student_name'] }}</td>
                    <td>{{ $entry['class_name'] ?? 'Sin curso' }}</td>
                    <td>{{ $entry['points'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Todavía no hay movimientos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Cursos</h2>

    <table>
        <thead>
            <tr>
                <th>Posición</th>
                <th>Curso</th>
                <th>Estudiantes</th>
                <th>Puntos</th>
                <th>Promedio</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($classes as $class)
                <tr>
                    <td>{{ $class['rank'] }}</td>
                    <td>{{ $class['class_name'] }}</td>
                    <td>{{ $class['students'] }}</td>
                    <td>{{ $class['points'] }}</td>
                    <td>{{ $class['avg_points'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Todavía no hay cursos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p><a href="{{ route('student.dashboard') }}">Volver al panel</a></p>
@endsection
