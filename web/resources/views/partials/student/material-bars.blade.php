@php
    $tones = [
        'plastic' => 'blue',
        'metal' => 'coral',
        'glass' => 'turquoise',
        'paper' => 'amber',
        'other' => 'pink',
    ];
    $names = [
        'plastic' => 'Plástico',
        'metal' => 'Metal',
        'glass' => 'Vidrio',
        'paper' => 'Papel',
        'other' => 'Otro',
    ];
    $order = ['plastic', 'metal', 'glass', 'paper', 'other'];
    $maxItems = max(array_column($byMaterial, 'items')) ?: 1;
    $rates = collect($order)
        ->map(fn (string $key) => ($names[$key] ?? $key).' '.($byMaterial[$key]['rate'] ?? 0))
        ->implode(' · ');
@endphp

<div class="bars">
    @foreach ($order as $key)
        @php
            $row = $byMaterial[$key] ?? ['items' => 0, 'points' => 0, 'rate' => 0];
        @endphp

        <div class="bar-row">
            <div class="bar-row__head">
                <span>{{ $names[$key] }}</span>
                <span class="bar-row__value">
                    {{ number_format($row['items'], 0, ',', '.') }} ·
                    {{ number_format($row['points'], 0, ',', '.') }} pts
                </span>
            </div>
            <div
                class="bar"
                role="img"
                aria-label="{{ $names[$key].': '.$row['items'].' depósitos, '.$row['points'].' puntos' }}"
            >
                <span
                    class="bar__fill bar__fill--{{ $tones[$key] }}"
                    style="width: {{ $row['items'] > 0 ? max(2, round($row['items'] / $maxItems * 100)) : 0 }}%"
                ></span>
            </div>
        </div>
    @endforeach
</div>

<p class="bars__note">Puntos por material: {{ $rates }} pts.</p>
