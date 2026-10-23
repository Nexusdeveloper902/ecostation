<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partials.head')
</head>
<body class="app-body">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>

    @php
        $user = auth()->user();
        $roleLabel = match (true) {
            $user?->isAdmin() => 'Administración',
            $user?->isTeacher() => 'Docente',
            $user?->isStudent() => 'Estudiante',
            default => 'Estación',
        };
        $roleDetail = $user?->isStudent() ? $user->student?->schoolClass?->name : null;
    @endphp

    <div class="app">
        @include('layouts.partials.sidebar')

        <div class="app__main">
            <header class="topbar">
                <div class="topbar__inner">
                    <a class="topbar__mobile-brand" href="{{ route('home') }}">
                        <img src="{{ asset('img/ecostation-horizontal.svg') }}" alt="EcoStation" width="414" height="120">
                    </a>

                    <div class="topbar__titles">
                        @hasSection('eyebrow')
                            <p class="topbar__eyebrow">@yield('eyebrow')</p>
                        @endif

                        <h1 class="topbar__title">
                            @hasSection('heading')
                                @yield('heading')
                            @else
                                @yield('title', 'EcoStation')
                            @endif
                        </h1>

                        @hasSection('subtitle')
                            <p class="topbar__subtitle">@yield('subtitle')</p>
                        @endif
                    </div>

                    <div class="topbar__side">
                        @yield('actions')

                        <details class="user-menu">
                            <summary aria-label="Menú de {{ $user?->name }}">
                                <x-avatar :name="$user?->name ?? 'EcoStation'" />
                                <span class="user-pill__meta">
                                    <span class="user-pill__name">{{ $user?->name }}</span>
                                    <span class="user-pill__role">
                                        {{ $roleLabel }}@if ($roleDetail) · {{ $roleDetail }}@endif
                                    </span>
                                </span>
                                <x-icon name="chevron-down" class="user-menu__chevron" />
                            </summary>

                            <div class="user-menu__panel">
                                <a class="user-menu__item" href="{{ route('password.change') }}">
                                    <x-icon name="lock" />
                                    <span>Cambiar contraseña</span>
                                </a>
                                <div class="user-menu__separator" role="presentation"></div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="user-menu__item" type="submit">
                                        <x-icon name="logout" />
                                        <span>Cerrar sesión</span>
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>
                </div>
            </header>

            <main class="page" id="contenido">
                @include('layouts.partials.flashes')

                @hasSection('filters')
                    <div class="page-filters">@yield('filters')</div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    @include('layouts.partials.bottom-nav')

    <script src="{{ asset('js/ui.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
