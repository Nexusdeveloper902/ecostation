@props(['points' => 0, 'label' => null])

@php
    $value = (int) $points;
    $formatted = number_format(abs($value), 0, ',', '.');
@endphp

<span {{ $attributes->class(['pts', $value <= 0 ? 'pts--zero' : null]) }}>
    @if ($value > 0)
        <span class="pts__star" aria-hidden="true">★</span>
        +{{ $formatted }}
    @else
        {{ $formatted }}
    @endif
    <span class="sr-only">{{ $label ?? 'puntos' }}</span>
    <span aria-hidden="true">pts</span>
</span>
