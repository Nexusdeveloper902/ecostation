<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partials.head')
</head>
<body>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>

    <main class="station" id="contenido">
        <div class="station__card">
            <a class="station__logo" href="{{ route('home') }}">
                <img src="{{ asset('img/ecostation-horizontal.svg') }}" alt="EcoStation" width="414" height="120">
            </a>

            <img class="station__boti" src="{{ asset('img/boti.svg') }}" alt="" width="120" height="120" aria-hidden="true">

            @include('layouts.partials.flashes')

            @yield('content')
        </div>
    </main>

    <script src="{{ asset('js/ui.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
