@props(['label' => 'Tabla de datos'])

<div {{ $attributes->class(['table-wrap']) }} tabindex="0" role="region" aria-label="{{ $label }}">
    {{ $slot }}
</div>
