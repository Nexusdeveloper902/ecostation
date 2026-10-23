@extends('layouts.app')

@section('title', 'Estudiante '.$student->name)

@section('content')
    <h1>{{ $student->name }}</h1>

    <p>{{ $history['student']['class_name'] ?? 'Sin curso' }}</p>

    <dl>
        <dt>Depósitos</dt>
        <dd>{{ $history['totals']['items'] }}</dd>

        <dt>Puntos</dt>
        <dd>{{ $history['totals']['points'] }}</dd>
    </dl>

    <h2>Últimos depósitos</h2>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Material</th>
                <th>Puntos</th>
                <th>Reciclable</th>
                <th>Estación</th>
                <th>Foto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($history['deposits'] as $deposit)
                <tr>
                    <td>{{ $deposit['date'] }}</td>
                    <td>{{ $deposit['time'] }}</td>
                    <td>{{ $deposit['material'] }}</td>
                    <td>{{ $deposit['points'] }}</td>
                    <td>{{ $deposit['is_recyclable'] ? 'Sí' : 'No' }}</td>
                    <td>{{ $deposit['reader'] ?? '—' }}</td>
                    <td>
                        @if ($deposit['has_photo'])
                            <a href="{{ route('deposits.photo', $deposit['deposit_id']) }}">Ver foto</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Sin depósitos en los últimos 30 días.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p>
        <a href="{{ route('teacher.student.export.csv', $student) }}">Exportar CSV</a>
        · <a href="{{ route('teacher.student.export.pdf', $student) }}">Exportar PDF</a>
    </p>

    <p><a href="{{ route('teacher.dashboard') }}">Volver al panel</a></p>
@endsection
