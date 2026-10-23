@props(['icon' => 'leaf', 'title', 'text' => null])

<div {{ $attributes->class(['empty']) }}>
    <span class="empty__art" aria-hidden="true">
        <x-icon :name="$icon" />
    </span>
    <p class="empty__title">{{ $title }}</p>
    @if ($text)
        <p class="empty__text">{{ $text }}</p>
    @endif
    {{ $slot }}
</div>
