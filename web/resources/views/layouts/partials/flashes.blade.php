@if (session('status'))
    @php $statusIsWarning = session('status') === __('auth.must_change_password'); @endphp

    <div class="toast-stack" aria-live="polite" aria-atomic="false">
        <div class="toast {{ $statusIsWarning ? 'toast--warning' : 'toast--success' }}" role="status">
            <span class="toast__icon" aria-hidden="true">
                <x-icon :name="$statusIsWarning ? 'warning' : 'check'" />
            </span>
            <p class="toast__text">{{ session('status') }}</p>
            <button class="toast__close" type="button" data-dismiss-toast aria-label="Cerrar aviso">
                <x-icon name="error" :size="16" />
            </button>
        </div>
    </div>
@endif

@if (session('error'))
    <div class="toast-stack" aria-live="assertive">
        <div class="toast toast--danger" role="alert">
            <span class="toast__icon" aria-hidden="true">
                <x-icon name="warning" />
            </span>
            <p class="toast__text">{{ session('error') }}</p>
            <button class="toast__close" type="button" data-dismiss-toast aria-label="Cerrar aviso">
                <x-icon name="error" :size="16" />
            </button>
        </div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert--danger error-summary" role="alert" aria-live="assertive">
        <x-icon name="warning" />
        <div>
            <p class="alert__title">Revisa los datos del formulario.</p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
