@props(['material', 'withValue' => false, 'dot' => true])

@php
    $key = $material instanceof \App\Enums\MaterialClass ? $material->value : (string) $material;
    $map = [
        'plastic' => ['label' => 'Plástico', 'tone' => 'blue'],
        'metal' => ['label' => 'Metal', 'tone' => 'coral'],
        'glass' => ['label' => 'Vidrio', 'tone' => 'turquoise'],
        'paper' => ['label' => 'Papel', 'tone' => 'amber'],
        'other' => ['label' => 'Otro', 'tone' => 'pink'],
    ];
    $entry = $map[$key] ?? ['label' => mb_convert_case($key, MB_CASE_TITLE, 'UTF-8'), 'tone' => 'pink'];
@endphp

<span {{ $attributes->class(['chip', 'chip--'.$entry['tone']]) }} @if ($withValue) data-material="{{ $key }}" @endif>
    @if ($dot)
        <span class="chip__dot" aria-hidden="true"></span>
    @endif
    {{ $entry['label'] }}
</span>
