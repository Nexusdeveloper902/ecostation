@extends('layouts.app')

@section('title', 'Mi panel')

@section('content')
    <h1>Mi panel</h1>

    <p>{{ $student->name }} — {{ $student->schoolClass?->name ?? 'Sin curso' }}</p>

    <dl>
        <dt>Saldo</dt>
        <dd>{{ $balance }} puntos</dd>

        <dt>Posición</dt>
        <dd>{{ $rank ?? 'Sin clasificar' }}</dd>
    </dl>

    <h2>Últimos depósitos</h2>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Material</th>
                <th>Puntos</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recent as $deposit)
                <tr>
                    <td>{{ $deposit->occurred_at }}</td>
                    <td>{{ $deposit->material_class->value }}</td>
                    <td>{{ $deposit->points_awarded }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Todavía no tienes depósitos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p>
        <a href="{{ route('student.history') }}">Ver historial completo</a>
        · <a href="{{ route('student.leaderboard') }}">Ver clasificación</a>
    </p>
@endsection
