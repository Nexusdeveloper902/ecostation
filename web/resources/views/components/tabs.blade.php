@props(['items' => [], 'label' => 'Vistas'])

<nav {{ $attributes->class(['tabs']) }} aria-label="{{ $label }}">
    @foreach ($items as $item)
        <a
            class="tab"
            href="{{ $item['href'] ?? '#' }}"
            @if (! empty($item['active'])) aria-current="page" @endif
        >
            {{ $item['label'] }}
            @if (isset($item['count']))
                <span class="tab__count">{{ number_format((int) $item['count'], 0, ',', '.') }}</span>
            @endif
        </a>
    @endforeach
</nav>
