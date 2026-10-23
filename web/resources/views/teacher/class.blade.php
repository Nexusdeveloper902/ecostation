@extends('layouts.app')

@section('title', 'Curso '.$class->name)

@section('content')
    <h1>Curso {{ $class->name }}</h1>

    <p>Del {{ $from }} al {{ $to }}.</p>

    <h2>Estudiantes</h2>

    <table>
        <thead>
            <tr>
                <th>Estudiante</th>
                <th>Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $student)
                <tr>
                    <td><a href="{{ route('teacher.student', $student['id']) }}">{{ $student['name'] }}</a></td>
                    <td>{{ $student['balance'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">Este curso no tiene estudiantes.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Materiales del curso</h2>

    <table>
        <thead>
            <tr>
                <th>Material</th>
                <th>Ítems</th>
                <th>Puntos</th>
                <th>Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($byMaterial as $material => $row)
                <tr>
                    <td>{{ $material }}</td>
                    <td>{{ $row['items'] }}</td>
                    <td>{{ $row['points'] }}</td>
                    <td>{{ $row['rate'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p><a href="{{ route('teacher.dashboard') }}">Volver al panel</a></p>
@endsection
