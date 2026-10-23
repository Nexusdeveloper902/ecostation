@extends('layouts.app')

@section('title', 'Cursos')

@section('content')
    <h1>Cursos</h1>

    <table>
        <thead>
            <tr>
                <th>Curso</th>
                <th>Estudiantes</th>
                <th>Docentes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($classes as $class)
                <tr>
                    <td>{{ $class->name }}</td>
                    <td>{{ $class->students_count }}</td>
                    <td>{{ $class->teachers_count }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Todavía no hay cursos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Crear curso</h2>

    <form method="POST" action="{{ route('admin.classes.store') }}">
        @csrf

        <p>
            <label for="class-name">Nombre</label>
            <input id="class-name" name="name" type="text" required>
        </p>

        <button type="submit">Crear curso</button>
    </form>

    <h2>Asignar docentes</h2>

    @foreach ($classes as $class)
        <form method="POST" action="{{ route('admin.classes.teachers', $class) }}">
            @csrf
            @method('PUT')

            <fieldset>
                <legend>{{ $class->name }}</legend>

                <label for="teachers-{{ $class->id }}">Docentes</label>
                <select id="teachers-{{ $class->id }}" name="teacher_ids[]" multiple>
                    @foreach ($teachers as $teacher)
                        <option value="{{ $teacher->id }}" @selected($class->teachers->contains('id', $teacher->id))>
                            {{ $teacher->name }}
                        </option>
                    @endforeach
                </select>

                <button type="submit">Guardar docentes</button>
            </fieldset>
        </form>
    @endforeach
@endsection
