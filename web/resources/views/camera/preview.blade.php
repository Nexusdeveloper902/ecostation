@extends('layouts.app')

@section('title', 'Cámara')
@section('heading', 'Cámara')
@section('subtitle', 'Vista en vivo de la estación')

@section('content')
    <section class="card" aria-labelledby="camera-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="camera-title">Estación en vivo</h2>
                <p class="card__subtitle">
                    La estación sirve un JPEG por petición en <code>/stream-frame</code>;
                    esta página lo refresca sin recargar.
                </p>
            </div>
            @if ($url)
                <a class="btn btn--outline btn--sm" href="{{ $url }}" target="_blank" rel="noopener">
                    <x-icon name="broadcast" />
                    Abrir visualizador
                </a>
            @endif
        </div>

        <div class="card__body">
            @if ($url)
                <div class="camera-stage">
                    <img
                        id="cameraFrame"
                        src="{{ $url }}/stream-frame"
                        data-url="{{ $url }}"
                        alt="Cámara en vivo de la estación"
                    >
                </div>
                <p class="camera-status" id="cameraStatus" role="status">Conectando con la cámara…</p>
            @else
                <x-empty
                    icon="broadcast"
                    title="Falta la URL de la cámara"
                    text="En Admin → Ajustes pega la dirección del visualizador de la estación, por ejemplo http://192.168.40.192 ."
                >
                    @if (auth()->user()?->isAdmin())
                        <a class="btn btn--primary btn--sm" href="{{ route('admin.settings') }}">Ir a Ajustes</a>
                    @endif
                </x-empty>
            @endif
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const image = document.getElementById("cameraFrame");
            if (!image) {
                return;
            }

            const status = document.getElementById("cameraStatus");
            const base = image.dataset.url;
            let timer = null;

            const refresh = () => {
                image.src = `${base}/stream-frame?t=${Date.now()}`;
            };

            const start = () => {
                if (timer !== null) {
                    return;
                }
                refresh();
                timer = window.setInterval(refresh, 400);
            };

            const stop = () => {
                if (timer !== null) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };

            image.addEventListener("load", () => {
                if (status) {
                    status.textContent = "En vivo · actualizando";
                }
            });

            image.addEventListener("error", () => {
                if (status) {
                    status.textContent = "Sin señal de la cámara. Revisa la estación o su URL en Ajustes.";
                }
            });

            document.addEventListener("visibilitychange", () => {
                if (document.hidden) {
                    stop();
                } else {
                    start();
                }
            });

            start();
        })();
    </script>
@endpush
