@php
    $user = auth()->user();
    $isTeacher = $user?->isTeacher() ?? false;
    $isAdmin = $user?->isAdmin() ?? false;
    $navClasses = $isTeacher ? $user->classes()->orderBy('name')->get() : collect();
    $studentsHref = $isTeacher && $navClasses->isNotEmpty()
        ? route('teacher.class', $navClasses->first())
        : ($isTeacher ? route('teacher.dashboard') : route('admin.students'));
@endphp

<nav class="bottom-nav" aria-label="Navegación principal">
    @if ($user?->isStudent())
        <a class="bottom-nav__item" href="{{ route('student.dashboard') }}" @if (request()->routeIs('student.dashboard')) aria-current="page" @endif>
            <x-icon name="home" />
            <span>Inicio</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('student.history') }}" @if (request()->routeIs('student.history')) aria-current="page" @endif>
            <x-icon name="clock" />
            <span>Historial</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('student.leaderboard') }}" @if (request()->routeIs('student.leaderboard')) aria-current="page" @endif>
            <x-icon name="trophy" />
            <span>Ranking</span>
        </a>
    @elseif ($isAdmin)
        <a class="bottom-nav__item" href="{{ route('teacher.dashboard') }}" @if (request()->routeIs('teacher.dashboard')) aria-current="page" @endif>
            <x-icon name="chart" />
            <span>Resumen</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('admin.students') }}" @if (request()->routeIs('admin.students', 'admin.classes')) aria-current="page" @endif>
            <x-icon name="users" />
            <span>Estudiantes</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('admin.pairing') }}" @if (request()->routeIs('admin.pairing')) aria-current="page" @endif>
            <x-icon name="link" />
            <span>Vincular</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('admin.stations') }}" @if (request()->routeIs('admin.stations')) aria-current="page" @endif>
            <x-icon name="station" />
            <span>Estaciones</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('admin.settings') }}" @if (request()->routeIs('admin.settings')) aria-current="page" @endif>
            <x-icon name="settings" />
            <span>Ajustes</span>
        </a>
    @else
        <a class="bottom-nav__item" href="{{ route('teacher.dashboard') }}" @if (request()->routeIs('teacher.dashboard')) aria-current="page" @endif>
            <x-icon name="chart" />
            <span>Resumen</span>
        </a>
        <a class="bottom-nav__item" href="{{ $studentsHref }}" @if (request()->routeIs('teacher.class', 'teacher.student')) aria-current="page" @endif>
            <x-icon name="users" />
            <span>Estudiantes</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('teacher.live') }}" @if (request()->routeIs('teacher.live')) aria-current="page" @endif>
            <x-icon name="broadcast" />
            <span>En vivo</span>
        </a>
        <a class="bottom-nav__item" href="{{ route('teacher.export') }}" @if (request()->routeIs('teacher.export', 'teacher.export.csv', 'teacher.export.pdf')) aria-current="page" @endif>
            <x-icon name="download" />
            <span>Exportar</span>
        </a>
    @endif
</nav>
