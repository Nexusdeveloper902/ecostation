@extends('layouts.app')

@section('title', 'Personal')
@section('heading', 'Personal')
@section('subtitle', 'Docentes y coordinadores con acceso al panel')

@php
    $roleLabel = fn (string $role): string => match ($role) {
        'admin' => 'Administrador',
        'teacher' => 'Docente',
        'station' => 'Estación / parlante',
        default => ucfirst($role),
    };
    $roleTone = fn (string $role): string => match ($role) {
        'admin' => 'turquoise',
        'teacher' => 'blue',
        'station' => 'neutral',
        default => 'neutral',
    };
    $teacherCount = $staff->filter(fn ($account) => $account->isTeacher())->count();
@endphp

@section('content')
    @if (session('account_email') && session('account_password'))
        <div class="secret-panel" role="status">
            <span class="secret-panel__icon" aria-hidden="true"><x-icon name="key" /></span>
            <div class="secret-panel__body">
                <p class="secret-panel__title">Cuenta creada</p>
                <p class="small">
                    Comparte estas credenciales una sola vez. El docente deberá cambiar la contraseña al entrar.
                </p>
                <div class="secret-row">
                    <span>{{ session('account_email') }}</span>
                    <code class="secret-code" id="new-staff-password">{{ session('account_password') }}</code>
                    <button class="btn btn--outline btn--sm" type="button" data-copy-target="#new-staff-password" data-copy-label="Copiar clave">
                        Copiar clave
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="toolbar">
        <div class="station-stats" aria-label="Resumen de personal">
            <span class="stat-pill"><strong>{{ $staff->count() }}</strong> cuentas</span>
            <span class="stat-pill"><strong>{{ $teacherCount }}</strong> docentes</span>
        </div>

        <button class="btn btn--primary" type="button" data-modal-open="modal-staff">
            <x-icon name="plus" />
            Nuevo usuario
        </button>
    </div>

    <section class="card" aria-labelledby="staff-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="staff-title">Cuentas del panel</h2>
                <p class="card__subtitle">Cada docente solo ve los cursos que tenga asignados.</p>
            </div>
        </div>

        <div class="card__body card__body--flush">
            <x-table-wrapper label="Personal" class="table-wrap--visible">
                <table class="data-table data-table--roomy admin-table">
                    <caption class="sr-only">Personas con acceso al panel y sus cursos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Persona</th>
                            <th scope="col">Rol</th>
                            <th scope="col">Cursos asignados</th>
                            <th scope="col" class="cell-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($staff as $user)
                            @php
                                $role = $user->role->value;
                                $assigned = $user->classes->pluck('name');
                            @endphp

                            <tr>
                                <td data-label="Persona">
                                    <div class="person-cell">
                                        <x-avatar :name="$user->name" />
                                        <div class="person-cell__body">
                                            <span class="cell-strong">{{ $user->name }}</span>
                                            <span class="cell-meta">{{ $user->email }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Rol">
                                    <span class="chip chip--{{ $roleTone($role) }}">
                                        <span class="chip__dot" aria-hidden="true"></span>
                                        {{ $roleLabel($role) }}
                                    </span>
                                </td>

                                <td data-label="Cursos">
                                    @if ($role === 'admin')
                                        <span class="muted">Todos los cursos</span>
                                    @elseif ($role === 'station')
                                        <span class="muted">No aplica</span>
                                    @elseif ($assigned->isEmpty())
                                        <span class="cell-meta">Sin cursos asignados</span>
                                    @else
                                        <span class="class-name">{{ $assigned->join(' · ') }}</span>
                                    @endif
                                </td>

                                <td data-label="Acciones" class="cell-right">
                                    @if ($role === 'teacher')
                                        <a class="btn btn--outline btn--sm" href="{{ route('admin.classes') }}">
                                            Editar cursos
                                        </a>
                                    @elseif ($role === 'station')
                                        <span class="chip chip--neutral">
                                            <span class="chip__dot" aria-hidden="true"></span>
                                            No rota clave
                                        </span>
                                    @else
                                        <span class="muted small">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-empty
                                        icon="users"
                                        title="Todavía no hay cuentas de personal"
                                        text="Crea una cuenta de docente o de estación para empezar."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-wrapper>
        </div>
    </section>

    <div class="notice">
        <x-icon name="users" />
        <p>
            Cada docente solo ve los cursos asignados aquí: resumen, estudiantes, en vivo y exportaciones.
            La cuenta de estación nunca se obliga a rotar la contraseña, porque el parlante inicia sesión solo.
        </p>
    </div>

    <dialog class="modal" id="modal-staff" aria-labelledby="modal-staff-title" @if (old('_form') === 'staff') data-modal-auto-open @endif>
        <form class="modal__panel" method="POST" action="{{ route('admin.staff.store') }}">
            @csrf
            <input type="hidden" name="_form" value="staff">

            <div class="modal__header">
                <div>
                    <h2 class="modal__title" id="modal-staff-title">Nuevo usuario</h2>
                    <p class="modal__subtitle">Docentes, coordinadores o la cuenta del parlante.</p>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Cerrar">
                    <x-icon name="error" :size="18" />
                </button>
            </div>

            <div class="modal__body">
                <x-field name="name" label="Nombre completo" icon="user" required autofocus />
                <x-field name="email" label="Correo" type="email" icon="mail" required autocomplete="off" />

                <x-select
                    name="role"
                    label="Rol"
                    :options="[
                        'teacher' => 'Docente',
                        'admin' => 'Administrador',
                        'station' => 'Estación / parlante',
                    ]"
                    required
                    icon="user"
                    help="El personal administrativo y docente cambia la clave al primer ingreso; la estación no."
                />

                <x-field
                    name="password"
                    label="Contraseña temporal"
                    type="password"
                    icon="lock"
                    required
                    autocomplete="new-password"
                    help="Mínimo 6 caracteres. Se muestra una sola vez."
                />
            </div>

            <div class="modal__footer">
                <button class="btn btn--outline" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn--primary" type="submit">Crear cuenta</button>
            </div>
        </form>
    </dialog>
@endsection
