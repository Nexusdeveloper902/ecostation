@extends('layouts.app')

@section('title', 'Emparejamiento')

@section('content')
    <h1>Mesa de emparejamiento</h1>

    <h2>Ventana activa</h2>

    @if ($status['pending'] !== null)
        <dl>
            <dt>Estudiante</dt>
            <dd>{{ $status['pending']['student_name'] }}</dd>

            <dt>Expira</dt>
            <dd>{{ $status['pending']['expires_at'] }}</dd>

            <dt>Segundos restantes</dt>
            <dd>{{ $status['pending']['seconds_left'] }}</dd>
        </dl>

        @if ($status['pending']['last_rejection'] !== null)
            <p role="alert">
                Último rechazo: {{ $status['pending']['last_rejection']['card_uid'] }}
                ({{ $status['pending']['last_rejection']['reason'] }})
            </p>
        @endif
    @else
        <p>No hay una ventana armada.</p>
    @endif

    <h2>Armar ventana</h2>

    <form method="POST" action="{{ route('admin.pairing.arm') }}">
        @csrf

        <p>
            <label for="pairing-student">Estudiante</label>
            <select id="pairing-student" name="student_id" required>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}">
                        {{ $student->name }} — {{ $student->schoolClass?->name ?? 'Sin curso' }}
                    </option>
                @endforeach
            </select>
        </p>

        <button type="submit">Armar</button>
    </form>

    <h2>Últimos emparejamientos</h2>

    <table>
        <thead>
            <tr>
                <th>Credencial</th>
                <th>Tipo</th>
                <th>Estudiante</th>
                <th>Estación</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($status['recent_pairings'] as $pairing)
                <tr>
                    <td>{{ $pairing['card_uid'] }}</td>
                    <td>{{ $pairing['card_kind'] === 'hce' ? 'Teléfono' : 'Física' }}</td>
                    <td>{{ $pairing['student_name'] }}</td>
                    <td>{{ $pairing['reader_label'] ?? '—' }}</td>
                    <td>{{ $pairing['paired_at'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Todavía no hay emparejamientos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Credenciales</h2>

    <table>
        <thead>
            <tr>
                <th>Estudiante</th>
                <th>Curso</th>
                <th>Credencial</th>
                <th>Tipo</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($students as $student)
                @forelse ($student->cards as $card)
                    <tr>
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->schoolClass?->name ?? 'Sin curso' }}</td>
                        <td>{{ $card->credential_uid }}</td>
                        <td>{{ $card->kind->value === 'hce' ? 'Teléfono' : 'Física' }}</td>
                        <td>{{ $card->status->value === 'active' ? 'Activa' : 'Revocada' }}</td>
                        <td>
                            @if ($card->status->value === 'active')
                                <form method="POST" action="{{ route('admin.cards.revoke', $card) }}">
                                    @csrf
                                    <button type="submit">Revocar</button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('admin.cards.destroy', $card) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit">Desemparejar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->schoolClass?->name ?? 'Sin curso' }}</td>
                        <td colspan="4">Sin credenciales.</td>
                    </tr>
                @endforelse
            @endforeach
        </tbody>
    </table>
@endsection
