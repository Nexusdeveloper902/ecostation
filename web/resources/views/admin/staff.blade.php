@extends('layouts.app')

@section('title', 'Personal')

@section('content')
    <h1>Personal</h1>

    @if (session('account_email'))
        <p role="status">
            Cuenta: {{ session('account_email') }} · contraseña inicial: {{ session('account_password') }}
        </p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Cursos</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($staff as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->role->value }}</td>
                    <td>{{ $user->classes->pluck('name')->join(', ') ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Todavía no hay cuentas de personal.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Crear cuenta</h2>

    <form method="POST" action="{{ route('admin.staff.store') }}">
        @csrf

        <p>
            <label for="staff-name">Nombre</label>
            <input id="staff-name" name="name" type="text" required>
        </p>

        <p>
            <label for="staff-email">Correo</label>
            <input id="staff-email" name="email" type="email" required>
        </p>

        <p>
            <label for="staff-role">Rol</label>
            <select id="staff-role" name="role" required>
                @foreach ($roles as $role)
                    <option value="{{ $role }}">{{ $role }}</option>
                @endforeach
            </select>
        </p>

        <p>
            <label for="staff-password">Contraseña temporal</label>
            <input id="staff-password" name="password" type="text" required minlength="6">
        </p>

        <button type="submit">Crear cuenta</button>
    </form>
@endsection
