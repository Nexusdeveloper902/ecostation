@php
    $user = auth()->user();
    $isTeacher = $user?->isTeacher() ?? false;
    $isAdmin = $user?->isAdmin() ?? false;
    $navClasses = $isTeacher
        ? $user->classes()->orderBy('name')->get()
        : collect();
    $studentClass = $user?->isStudent() ? $user->student?->schoolClass?->name : null;
    $studentsHref = $isTeacher && $navClasses->isNotEmpty()
        ? route('teacher.class', $navClasses->first())
        : ($isTeacher ? route('teacher.dashboard') : route('admin.students'));
@endphp

<aside class="sidebar" aria-label="Navegación principal">
    <a class="sidebar__brand" href="{{ route('home') }}">
        <img src="{{ asset('img/ecostation-horizontal.svg') }}" alt="EcoStation" width="414" height="120">
    </a>

    @if ($user?->isStudent())
        <p class="sidebar__role">Estudiante</p>
        <nav class="sidebar__nav" aria-label="Estudiante">
            <a class="nav-item" href="{{ route('student.dashboard') }}" @if (request()->routeIs('student.dashboard')) aria-current="page" @endif>
                <x-icon name="home" />
                <span>Inicio</span>
            </a>
            <a class="nav-item" href="{{ route('student.history') }}" @if (request()->routeIs('student.history')) aria-current="page" @endif>
                <x-icon name="clock" />
                <span>Historial</span>
            </a>
            <a class="nav-item" href="{{ route('student.leaderboard') }}" @if (request()->routeIs('student.leaderboard')) aria-current="page" @endif>
                <x-icon name="trophy" />
                <span>Ranking</span>
            </a>
        </nav>
    @elseif ($isAdmin)
        <p class="sidebar__role">Supervisión</p>
        <nav class="sidebar__nav" aria-label="Supervisión">
            <a class="nav-item" href="{{ route('teacher.dashboard') }}" @if (request()->routeIs('teacher.dashboard')) aria-current="page" @endif>
                <x-icon name="chart" />
                <span>Resumen</span>
            </a>
            <a class="nav-item" href="{{ route('teacher.live') }}" @if (request()->routeIs('teacher.live')) aria-current="page" @endif>
                <x-icon name="broadcast" />
                <span>En vivo</span>
            </a>
            <a class="nav-item" href="{{ route('camera.preview') }}" @if (request()->routeIs('camera.preview')) aria-current="page" @endif>
                <x-icon name="broadcast" />
                <span>Cámara</span>
            </a>
        </nav>

        <p class="sidebar__role">Administración</p>
        <nav class="sidebar__nav" aria-label="Administración">
            <a class="nav-item" href="{{ route('admin.students') }}" @if (request()->routeIs('admin.students', 'admin.classes')) aria-current="page" @endif>
                <x-icon name="users" />
                <span>Estudiantes y cursos</span>
            </a>
            <a class="nav-item" href="{{ route('admin.pairing') }}" @if (request()->routeIs('admin.pairing')) aria-current="page" @endif>
                <x-icon name="link" />
                <span>Vinculación</span>
            </a>
            <a class="nav-item" href="{{ route('admin.stations') }}" @if (request()->routeIs('admin.stations')) aria-current="page" @endif>
                <x-icon name="station" />
                <span>Estaciones</span>
            </a>
            <a class="nav-item" href="{{ route('admin.staff') }}" @if (request()->routeIs('admin.staff')) aria-current="page" @endif>
                <x-icon name="user" />
                <span>Personal</span>
            </a>
            <a class="nav-item" href="{{ route('admin.settings') }}" @if (request()->routeIs('admin.settings')) aria-current="page" @endif>
                <x-icon name="settings" />
                <span>Ajustes</span>
            </a>
        </nav>
    @else
        <p class="sidebar__role">Docente</p>
        <nav class="sidebar__nav" aria-label="Docente">
            <a class="nav-item" href="{{ route('teacher.dashboard') }}" @if (request()->routeIs('teacher.dashboard')) aria-current="page" @endif>
                <x-icon name="chart" />
                <span>Resumen</span>
            </a>
            <a class="nav-item" href="{{ $studentsHref }}" @if (request()->routeIs('teacher.class', 'teacher.student')) aria-current="page" @endif>
                <x-icon name="users" />
                <span>Estudiantes</span>
            </a>
            <a class="nav-item" href="{{ route('teacher.live') }}" @if (request()->routeIs('teacher.live')) aria-current="page" @endif>
                <x-icon name="broadcast" />
                <span>En vivo</span>
            </a>
            <a class="nav-item" href="{{ route('camera.preview') }}" @if (request()->routeIs('camera.preview')) aria-current="page" @endif>
                <x-icon name="station" />
                <span>Cámara</span>
            </a>
            <a class="nav-item" href="{{ route('teacher.export') }}" @if (request()->routeIs('teacher.export', 'teacher.export.csv', 'teacher.export.pdf', 'teacher.student.export.csv', 'teacher.student.export.pdf')) aria-current="page" @endif>
                <x-icon name="download" />
                <span>Exportar</span>
            </a>
        </nav>
    @endif

    <div class="sidebar__bottom">
        @if ($isTeacher && $navClasses->isNotEmpty())
            <div class="sidebar__card">
                <p class="sidebar__card-title">Mis cursos</p>
                <p class="sidebar__card-classes">
                    @foreach ($navClasses as $class)
                        <a href="{{ route('teacher.class', $class) }}">{{ $class->name }}</a>@if (! $loop->last) · @endif
                    @endforeach
                </p>
                <p class="sidebar__card-note">Solo ves tus cursos asignados.</p>
            </div>
        @endif

        <form class="sidebar__logout" method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="nav-item nav-item--button" type="submit">
                <x-icon name="logout" />
                <span>Cerrar sesión</span>
            </button>
        </form>
    </div>
</aside>
