@extends('layouts.app')

@section('title', 'Panel docente')

@section('content')
    <h1>Panel docente</h1>

    <form method="GET" action="{{ route('teacher.dashboard') }}">
        <label for="from">Desde</label>
        <input id="from" name="from" type="date" value="{{ $from }}">

        <label for="to">Hasta</label>
        <input id="to" name="to" type="date" value="{{ $to }}">

        <button type="submit">Filtrar</button>
    </form>

    <h2>Cursos</h2>

    <table>
        <thead>
            <tr>
                <th>Curso</th>
                <th>Ítems</th>
                <th>Puntos</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($byClass as $row)
                <tr>
                    <td><a href="{{ route('teacher.class', $row['class_id']) }}">{{ $row['class_name'] }}</a></td>
                    <td>{{ $row['items'] }}</td>
                    <td>{{ $row['points'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">No hay cursos en tu ámbito.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Materiales</h2>

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

    <h2>Tendencia</h2>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Ítems</th>
                <th>Puntos</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($trend as $day)
                <tr>
                    <td>{{ $day['date'] }}</td>
                    <td>{{ $day['items'] }}</td>
                    <td>{{ $day['points'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>
        <a href="{{ route('teacher.live') }}">Ver feed en vivo</a>
        · <a href="{{ route('teacher.export.csv') }}">Exportar CSV</a>
        · <a href="{{ route('teacher.export.pdf') }}">Exportar PDF</a>
    </p>
@endsection
