@extends('layouts.app')

@section('title', 'Mi historial')

@section('content')
    <h1>Mi historial</h1>

    <p>{{ $student->name }} — {{ $student->schoolClass?->name ?? 'Sin curso' }}</p>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Material</th>
                <th>Puntos</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($deposits as $deposit)
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
        @if (! $deposits->onFirstPage())
            <a href="{{ $deposits->previousPageUrl() }}">Anterior</a>
        @endif
        @if ($deposits->hasMorePages())
            <a href="{{ $deposits->nextPageUrl() }}">Siguiente</a>
        @endif
    </p>

    <p><a href="{{ route('student.dashboard') }}">Volver al panel</a></p>
@endsection
