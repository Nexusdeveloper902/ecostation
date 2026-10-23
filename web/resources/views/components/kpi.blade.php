@props(['label', 'value', 'hint' => null, 'hintTone' => null, 'text' => false])

<article {{ $attributes->class(['kpi']) }}>
    <p class="kpi__label">{{ $label }}</p>
    <p class="kpi__value {{ $text ? 'kpi__value--text' : '' }}">{{ $value }}</p>
    @if ($hint)
        <p class="kpi__hint {{ $hintTone === 'up' ? 'kpi__hint--up' : '' }}">{{ $hint }}</p>
    @endif
    {{ $slot }}
</article>
