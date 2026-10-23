@extends('layouts.app')

@section('title', 'Cursos')
@section('heading', 'Cursos')
@section('subtitle', $classes->count().' cursos · asigna docentes y revisa sus estudiantes')

@section('actions')
    <button class="btn btn--primary btn--sm" type="button" data-modal-open="modal-class">
        <x-icon name="plus" />
        Nuevo curso
    </button>
@endsection

@section('content')
    @if ($classes->isEmpty())
        <section class="card">
            <x-empty
                icon="users"
                title="Todavía no hay cursos"
                text="Crea el primer curso para poder matricular estudiantes y asignar docentes."
            >
                <button class="btn btn--primary" type="button" data-modal-open="modal-class">
                    <x-icon name="plus" />
                    Nuevo curso
                </button>
            </x-empty>
        </section>
    @else
        <div class="class-grid">
            @foreach ($classes as $class)
                <section class="card class-card" id="class-{{ $class->id }}" aria-labelledby="class-title-{{ $class->id }}">
                    <div class="card__header">
                        <div>
                            <h2 class="card__title" id="class-title-{{ $class->id }}">{{ $class->name }}</h2>
                            <p class="card__subtitle">
                                {{ number_format($class->students_count, 0, ',', '.') }}
                                {{ $class->students_count === 1 ? 'estudiante' : 'estudiantes' }}
                                · {{ $class->teachers_count }}
                                {{ $class->teachers_count === 1 ? 'docente' : 'docentes' }}
                            </p>
                        </div>

                        <a class="card__link" href="{{ route('admin.students', ['class_id' => $class->id]) }}">
                            Ver estudiantes
                        </a>
                    </div>

                    <div class="card__body">
                        <form method="POST" action="{{ route('admin.classes.teachers', $class) }}" class="class-card__form">
                            @csrf
                            @method('PUT')

                            <label class="field__label" for="teachers-{{ $class->id }}">Docentes asignados</label>
                            <select
                                class="select select--multiple"
                                id="teachers-{{ $class->id }}"
                                name="teacher_ids[]"
                                multiple
                                size="{{ max(2, min(6, max(1, $teachers->count()))) }}"
                                aria-describedby="teachers-help-{{ $class->id }}"
                            >
                                @foreach ($teachers as $teacher)
                                    <option value="{{ $teacher->id }}" @selected($class->teachers->contains('id', $teacher->id))>
                                        {{ $teacher->name }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="field__help" id="teachers-help-{{ $class->id }}">
                                Usa Ctrl o Cmd para elegir varios. Un docente solo ve los cursos asignados aquí.
                            </p>

                            @if ($teachers->isEmpty())
                                <p class="field__help">Todavía no hay cuentas docentes. Créalas en Personal.</p>
                            @endif

                            <div class="class-card__actions">
                                <button class="btn btn--outline btn--sm" type="submit">Guardar docentes</button>
                            </div>
                        </form>
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    <dialog class="modal" id="modal-class" aria-labelledby="modal-class-title" @if (old('_form') === 'class') data-modal-auto-open @endif>
        <form class="modal__panel" method="POST" action="{{ route('admin.classes.store') }}">
            @csrf
            <input type="hidden" name="_form" value="class">

            <div class="modal__header">
                <div>
                    <h2 class="modal__title" id="modal-class-title">Nuevo curso</h2>
                    <p class="modal__subtitle">Después podrás asignarle docentes.</p>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Cerrar">
                    <x-icon name="error" :size="18" />
                </button>
            </div>

            <div class="modal__body">
                @php $classNameError = old('_form') === 'class' ? $errors->first('name') : null; @endphp

                <div class="field @if ($classNameError) field--error @endif">
                    <label class="field__label" for="class-name">Nombre del curso</label>
                    <div class="field__control">
                        <input
                            class="input"
                            id="class-name"
                            name="name"
                            type="text"
                            value="{{ old('_form') === 'class' ? old('name') : '' }}"
                            placeholder="6° A"
                            required
                            autofocus
                            @if ($classNameError) aria-invalid="true" aria-describedby="class-name-error" @endif
                        >
                    </div>
                    @if ($classNameError)
                        <p class="field__error" id="class-name-error">
                            <x-icon name="error" />
                            <span>{{ $classNameError }}</span>
                        </p>
                    @endif
                </div>
            </div>

            <div class="modal__footer">
                <button class="btn btn--outline" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn--primary" type="submit">Crear curso</button>
            </div>
        </form>
    </dialog>
@endsection
