<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partials.head')
</head>
<body>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>

    <div class="auth">
        <aside class="auth__brand" aria-label="EcoStation">
            <a class="auth__logo" href="{{ route('home') }}">
                <img src="{{ asset('img/ecostation-horizontal-oscuro.svg') }}" alt="EcoStation" width="414" height="120">
            </a>

            <div class="auth__center">
                <img class="auth__boti" src="{{ asset('img/boti.svg') }}" alt="" width="120" height="120" aria-hidden="true">

                <p class="display auth__display">Recicla, toca<br>y suma puntos.</p>
                <p class="auth__copy">
                    Tu carné o tu teléfono es tu llave: tócalo en la estación, deposita la botella y sigue tu camino. Los puntos llegan solos.
                </p>
            </div>

            <p class="auth__footer">Colegio Distrital Los Andes · Bogotá</p>
        </aside>

        <main class="auth__form-side" id="contenido">
            <div class="auth__form">
                <a class="auth__mobile-brand" href="{{ route('home') }}">
                    <img src="{{ asset('img/ecostation-horizontal.svg') }}" alt="EcoStation" width="414" height="120">
                </a>

                @include('layouts.partials.flashes')

                @yield('content')
            </div>
        </main>
    </div>

    <script src="{{ asset('js/ui.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
