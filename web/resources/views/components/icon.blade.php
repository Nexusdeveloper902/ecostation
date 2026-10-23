@props(['name', 'size' => 20])

<svg {{ $attributes->merge([
    'class' => 'icon',
    'width' => $size,
    'height' => $size,
    'aria-hidden' => 'true',
    'focusable' => 'false',
]) }}>
    <use href="{{ asset('img/icons.svg') }}#i-{{ $name }}"></use>
</svg>
