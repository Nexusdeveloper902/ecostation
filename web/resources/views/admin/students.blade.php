@extends('layouts.app')

@section('title', 'Estudiantes')

@section('content')
    <h1>Estudiantes</h1>

    @if (session('account_email'))
        <p role="status">
            Cuenta: {{ session('account_email') }} · contraseña inicial: {{ session('account_password') }}
        </p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Curso</th>
                <th>Tarjetas</th>
                <th>Saldo</th>
                <th>Cuenta</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $student)
                <tr>
                    <td>{{ $student->name }}</td>
                    <td>{{ $student->schoolClass?->name ?? 'Sin curso' }}</td>
                    <td>{{ $student->cards_count }}</td>
                    <td>{{ (int) ($student->balance ?? 0) }}</td>
                    <td>{{ $student->account?->email ?? 'Sin cuenta' }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.students.destroy', $student) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit">Eliminar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Todavía no hay estudiantes.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p>
        @if (! $students->onFirstPage())
            <a href="{{ $students->previousPageUrl() }}">Anterior</a>
        @endif
        @if ($students->hasMorePages())
            <a href="{{ $students->nextPageUrl() }}">Siguiente</a>
        @endif
    </p>

    <h2>Crear estudiante</h2>

    <form method="POST" action="{{ route('admin.students.store') }}">
        @csrf

        <p>
            <label for="student-name">Nombre</label>
            <input id="student-name" name="name" type="text" required>
        </p>

        <p>
            <label for="student-class">Curso</label>
            <select id="student-class" name="class_id" required>
                @foreach ($classes as $class)
                    <option value="{{ $class->id }}">{{ $class->name }}</option>
                @endforeach
            </select>
        </p>

        <button type="submit">Crear estudiante</button>
    </form>

    <h2>Importar CSV</h2>

    <p>El archivo debe tener el encabezado <code>nombre,curso</code>.</p>

    <form method="POST" action="{{ route('admin.students.import') }}" enctype="multipart/form-data">
        @csrf

        <p>
            <label for="student-file">Archivo CSV</label>
            <input id="student-file" name="file" type="file" accept=".csv,text/csv" required>
        </p>

        <button type="submit">Importar</button>
    </form>
@endsection
