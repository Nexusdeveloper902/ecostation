@extends('layouts.app')

@section('title', 'Estudiantes y cursos')
@section('heading', 'Estudiantes y cursos')
@section('subtitle', number_format($totalStudents, 0, ',', '.').' estudiantes en '.number_format($totalClasses, 0, ',', '.').' cursos')

@php
    $filterQuery = array_filter([
        'q' => $search !== '' ? $search : null,
        'credential' => $credentialFilter !== 'all' ? $credentialFilter : null,
    ], fn ($value) => $value !== null);

    $tabs = [[
        'label' => 'Todos',
        'count' => $totalStudents,
        'href' => route('admin.students', $filterQuery),
        'active' => $selectedClassId === 0,
    ]];

    foreach ($classes as $class) {
        $tabs[] = [
            'label' => $class->name,
            'count' => $class->students_count,
            'href' => route('admin.students', array_merge($filterQuery, ['class_id' => $class->id])),
            'active' => $selectedClassId === (int) $class->id,
        ];
    }
@endphp

@section('content')
    @if (session('account_email') && session('account_password'))
        <div class="secret-panel" role="status">
            <span class="secret-panel__icon" aria-hidden="true"><x-icon name="key" /></span>
            <div class="secret-panel__body">
                <p class="secret-panel__title">Cuenta del estudiante creada</p>
                <p class="small">
                    Entrega estas credenciales una sola vez. La contraseña no se puede volver a mostrar.
                </p>
                <div class="secret-row">
                    <span>{{ session('account_email') }}</span>
                    <code class="secret-code" id="new-student-password">{{ session('account_password') }}</code>
                    <button class="btn btn--outline btn--sm" type="button" data-copy-target="#new-student-password" data-copy-label="Copiar clave">
                        Copiar clave
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if (session('account_passwords'))
        <div class="secret-panel" role="status">
            <span class="secret-panel__icon" aria-hidden="true"><x-icon name="key" /></span>
            <div class="secret-panel__body">
                <p class="secret-panel__title">Contraseñas de la importación</p>
                <p class="small">
                    {{ session('creados', 0) }} cuentas creadas, {{ session('omitidos', 0) }} filas omitidas.
                    Copia estas claves ahora: no se vuelven a mostrar.
                </p>
                <div class="secret-list">
                    @foreach (session('account_passwords') as $credential)
                        <div class="secret-row">
                            <span>{{ $credential['email'] }}</span>
                            <code class="secret-code" id="import-password-{{ $loop->index }}">{{ $credential['password'] }}</code>
                            <button class="btn btn--outline btn--sm" type="button" data-copy-target="#import-password-{{ $loop->index }}" data-copy-label="Copiar">
                                Copiar
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <section aria-labelledby="roster-title">
        <h2 class="sr-only" id="roster-title">Estudiantes</h2>

        <div class="admin-tabs-row">
            <x-tabs :items="$tabs" label="Cursos" />

            <button class="btn btn--ghost btn--sm" type="button" data-modal-open="modal-class">
                <x-icon name="plus" />
                Nuevo curso
            </button>

            <a class="btn btn--ghost btn--sm" href="{{ route('admin.classes') }}">
                <x-icon name="settings" />
                Gestionar cursos
            </a>
        </div>

        <form class="toolbar admin-toolbar" method="GET" action="{{ route('admin.students') }}" role="search">
            @if ($selectedClassId > 0)
                <input type="hidden" name="class_id" value="{{ $selectedClassId }}">
            @endif

            <div class="toolbar__group">
                <label class="search-field" for="roster-q">
                    <x-icon name="search" />
                    <span class="sr-only">Buscar por nombre o correo</span>
                    <input
                        class="input"
                        id="roster-q"
                        name="q"
                        type="search"
                        value="{{ $search }}"
                        placeholder="Buscar por nombre o correo"
                        autocomplete="off"
                    >
                </label>

                <x-select
                    name="credential"
                    label="Credencial"
                    :options="[
                        'all' => 'Todas',
                        'card' => 'Tarjeta',
                        'phone' => 'Teléfono',
                        'linked' => 'Con credencial',
                        'unlinked' => 'Sin vincular',
                    ]"
                    :selected="$credentialFilter"
                    icon="card"
                    class="filters__field"
                />

                <button class="btn btn--outline btn--sm" type="submit">Aplicar</button>
            </div>

            <div class="toolbar__group">
                <button class="btn btn--outline" type="button" data-modal-open="modal-import">
                    <x-icon name="upload" />
                    Importar CSV
                </button>
                <button class="btn btn--primary" type="button" data-modal-open="modal-student">
                    <x-icon name="plus" />
                    Nuevo estudiante
                </button>
            </div>
        </form>

        <div class="card admin-table-card">
            <x-table-wrapper label="Estudiantes" class="table-wrap--visible">
                <table class="data-table data-table--roomy admin-table">
                    <caption class="sr-only">Estudiantes con su curso, credencial y puntos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Estudiante</th>
                            <th scope="col">Curso</th>
                            <th scope="col">Credencial</th>
                            <th scope="col">Puntos</th>
                            <th scope="col" class="cell-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($students as $student)
                            @php
                                $activeCards = $student->cards;
                                $hasPhysical = $activeCards->contains(fn ($card) => $card->kind === \App\Enums\CardKind::Physical);
                                $hasPhone = $activeCards->contains(fn ($card) => $card->kind === \App\Enums\CardKind::Hce);
                                $isPending = in_array((int) $student->id, $pendingStudentIds, true);
                                $isLinked = $hasPhysical || $hasPhone;
                            @endphp

                            <tr>
                                <td data-label="Estudiante">
                                    <div class="person-cell">
                                        <x-avatar :name="$student->name" />
                                        <div class="person-cell__body">
                                            <span class="cell-strong">{{ $student->name }}</span>
                                            <span class="cell-meta">{{ $student->account?->email ?? 'Sin cuenta' }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Curso">
                                    <span class="class-name">{{ $student->schoolClass?->name ?? 'Sin curso' }}</span>
                                </td>

                                <td data-label="Credencial">
                                    @if ($hasPhysical && $hasPhone)
                                        <span class="chip chip--success">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            Tarjeta + teléfono
                                        </span>
                                    @elseif ($hasPhone)
                                        <span class="chip chip--success">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            Teléfono
                                        </span>
                                    @elseif ($hasPhysical)
                                        <span class="chip chip--success">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            Tarjeta
                                        </span>
                                    @elseif ($isPending)
                                        <span class="chip chip--amber">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            Pendiente: tocar estación
                                        </span>
                                    @else
                                        <span class="chip chip--danger">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            Sin vincular
                                        </span>
                                    @endif
                                </td>

                                <td data-label="Puntos" class="cell-figure">
                                    {{ number_format((int) ($student->balance ?? 0), 0, ',', '.') }}
                                </td>

                                <td data-label="Acciones" class="cell-right">
                                    <div class="row-actions">
                                        @if ($isLinked)
                                            <a class="btn btn--outline btn--sm" href="{{ route('teacher.student', $student) }}">
                                                Gestionar
                                            </a>
                                        @else
                                            <form method="POST" action="{{ route('admin.pairing.arm') }}">
                                                @csrf
                                                <input type="hidden" name="student_id" value="{{ $student->id }}">
                                                <button class="btn btn--primary btn--sm" type="submit">Vincular</button>
                                            </form>
                                        @endif

                                        <details class="row-menu">
                                            <summary class="row-menu__trigger" aria-label="Más acciones para {{ $student->name }}">
                                                <x-icon name="chevron-down" :size="18" />
                                            </summary>

                                            <div class="row-menu__panel">
                                                @foreach ($activeCards as $card)
                                                    <form method="POST" action="{{ route('admin.cards.destroy', $card) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="row-menu__item" type="submit">
                                                            Desemparejar {{ $card->credential_uid }}
                                                        </button>
                                                    </form>
                                                @endforeach

                                                <form method="POST" action="{{ route('admin.students.destroy', $student) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="row-menu__item row-menu__item--danger" type="submit">
                                                        Eliminar estudiante
                                                    </button>
                                                </form>
                                            </div>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-empty
                                        icon="users"
                                        title="Sin estudiantes para este filtro"
                                        text="Cambia el curso o la credencial, o crea un estudiante nuevo."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-wrapper>

            <x-pagination :paginator="$students" label="estudiantes" />
        </div>
    </section>

    <dialog class="modal" id="modal-student" aria-labelledby="modal-student-title" @if (old('_form') === 'student') data-modal-auto-open @endif>
        <form class="modal__panel" method="POST" action="{{ route('admin.students.store') }}">
            @csrf
            <input type="hidden" name="_form" value="student">

            <div class="modal__header">
                <div>
                    <h2 class="modal__title" id="modal-student-title">Nuevo estudiante</h2>
                    <p class="modal__subtitle">Se crea también su cuenta para iniciar sesión.</p>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Cerrar">
                    <x-icon name="error" :size="18" />
                </button>
            </div>

            <div class="modal__body">
                @php $studentNameError = old('_form') === 'student' ? $errors->first('name') : null; @endphp

                <div class="field @if ($studentNameError) field--error @endif">
                    <label class="field__label" for="student-name">Nombre completo</label>
                    <div class="field__control">
                        <x-icon name="user" class="field__icon" />
                        <input
                            class="input"
                            id="student-name"
                            name="name"
                            type="text"
                            value="{{ old('_form') === 'student' ? old('name') : '' }}"
                            required
                            autofocus
                            @if ($studentNameError) aria-invalid="true" aria-describedby="student-name-error" @endif
                        >
                    </div>
                    @if ($studentNameError)
                        <p class="field__error" id="student-name-error">
                            <x-icon name="error" />
                            <span>{{ $studentNameError }}</span>
                        </p>
                    @endif
                    <p class="field__help">Como aparecerá en el ranking y los reportes.</p>
                </div>

                <x-select
                    name="class_id"
                    label="Curso"
                    :options="$classes->pluck('name', 'id')->all()"
                    required
                    icon="users"
                />

                <p class="field__help">
                    El correo se genera como <strong>nombre@{{ $studentEmailDomain }}</strong>.
                    La contraseña temporal se muestra una sola vez al guardar.
                </p>
            </div>

            <div class="modal__footer">
                <button class="btn btn--outline" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn--primary" type="submit">Crear estudiante</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="modal-import" aria-labelledby="modal-import-title" @if (old('_form') === 'import' || $errors->has('file')) data-modal-auto-open @endif>
        <form class="modal__panel" method="POST" action="{{ route('admin.students.import') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="_form" value="import">

            <div class="modal__header">
                <div>
                    <h2 class="modal__title" id="modal-import-title">Importar estudiantes</h2>
                    <p class="modal__subtitle">Un archivo CSV con el encabezado <code>nombre,curso</code>.</p>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Cerrar">
                    <x-icon name="error" :size="18" />
                </button>
            </div>

            <div class="modal__body">
                <label class="dropzone @error('file') is-error @enderror" data-dropzone for="student-file">
                    <x-icon name="upload" :size="28" />
                    <span class="dropzone__title">Arrastra el CSV o elige un archivo</span>
                    <span class="dropzone__name" data-dropzone-name data-empty-label="Ningún archivo seleccionado">
                        Ningún archivo seleccionado
                    </span>
                    <span class="dropzone__hint">Máximo 2 MB · columnas nombre, curso</span>
                    <input
                        class="sr-only"
                        id="student-file"
                        name="file"
                        type="file"
                        accept=".csv,text/csv"
                        required
                        data-dropzone-input
                    >
                </label>

                @error('file')
                    <p class="field__error"><x-icon name="error" /><span>{{ $message }}</span></p>
                @enderror

                <p class="field__help">
                    Los cursos que no existan se crean automáticamente. Las filas repetidas se omiten.
                    Al terminar verás las contraseñas una sola vez.
                </p>
            </div>

            <div class="modal__footer">
                <button class="btn btn--outline" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn--primary" type="submit">Importar CSV</button>
            </div>
        </form>
    </dialog>

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
