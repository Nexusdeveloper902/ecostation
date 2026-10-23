@props(['weeks' => [], 'byMaterial' => []])

@php
    $series = [
        ['key' => 'plastic', 'label' => 'Plástico', 'tone' => 'blue'],
        ['key' => 'metal', 'label' => 'Metal', 'tone' => 'coral'],
        ['key' => 'glass', 'label' => 'Vidrio', 'tone' => 'turquoise'],
        ['key' => 'paper', 'label' => 'Papel', 'tone' => 'amber'],
        ['key' => 'other', 'label' => 'Otro', 'tone' => 'pink'],
    ];

    $count = count($weeks);
    $max = 0;
    foreach ($weeks as $week) {
        $max = max($max, max($week['materials']));
    }

    // A clean axis: 0 / step / 2·step / 3·step / 4·step.
    $rawStep = max(1, $max / 4);
    $magnitude = 10 ** max(0, (int) floor(log10($rawStep)));
    $normalized = $rawStep / $magnitude;
    $step = ($normalized <= 1 ? 1 : ($normalized <= 2 ? 2 : ($normalized <= 5 ? 5 : 10))) * $magnitude;
    $step = max(1, (int) ceil($step));
    $ceiling = $step * 4;

    $width = 820;
    $height = 282;
    $padLeft = 42;
    $padRight = 18;
    $padTop = 14;
    $padBottom = 36;
    $plotWidth = $width - $padLeft - $padRight;
    $plotHeight = $height - $padTop - $padBottom;

    $xFor = fn (int $index) => $count <= 1
        ? $padLeft + $plotWidth / 2
        : $padLeft + ($index * ($plotWidth / ($count - 1)));

    $yFor = fn (int $value) => $padTop + $plotHeight - (($value / $ceiling) * $plotHeight);

    $hasData = collect($weeks)->sum('total') > 0;
    $labelStride = $count > 8 ? (int) ceil($count / 8) : 1;
@endphp

<div class="legend" aria-hidden="true">
    @foreach ($series as $item)
        <span class="legend__item">
            <span class="legend__dot legend__dot--{{ $item['tone'] }}"></span>
            {{ $item['label'] }}
        </span>
    @endforeach
</div>

@if (! $hasData)
    <div class="empty-chart">
        <p>Sin depósitos en el periodo seleccionado. Prueba con otro rango de fechas.</p>
    </div>
@else
    <div class="chart">
        <svg
            class="chart__svg"
            viewBox="0 0 {{ $width }} {{ $height }}"
            role="img"
            aria-label="Depósitos por material, por semana, del {{ $weeks[0]['label'] }} al {{ $weeks[$count - 1]['label'] }}"
        >
            @for ($line = 0; $line <= 4; $line++)
                @php
                    $value = $step * $line;
                    $y = $yFor($value);
                @endphp
                <line class="chart__grid" x1="{{ $padLeft }}" x2="{{ $width - $padRight }}" y1="{{ $y }}" y2="{{ $y }}" />
                <text class="chart__axis-label" x="{{ $padLeft - 8 }}" y="{{ $y + 4 }}" text-anchor="end">{{ $value }}</text>
            @endfor

            @foreach ($weeks as $index => $week)
                @if ($index % $labelStride === 0 || $index === $count - 1)
                    <text class="chart__axis-label" x="{{ $xFor($index) }}" y="{{ $height - 12 }}" text-anchor="middle">
                        {{ $week['label'] }}
                    </text>
                @endif
            @endforeach

            @foreach ($series as $item)
                @php
                    $points = [];
                    foreach ($weeks as $index => $week) {
                        $points[] = $xFor($index).','.$yFor((int) $week['materials'][$item['key']]);
                    }
                @endphp

                <polyline class="chart__line chart__line--{{ $item['tone'] }}" points="{{ implode(' ', $points) }}" />

                @foreach ($weeks as $index => $week)
                    <circle
                        class="chart__dot--{{ $item['tone'] }}"
                        cx="{{ $xFor($index) }}"
                        cy="{{ $yFor((int) $week['materials'][$item['key']]) }}"
                        r="3.2"
                        stroke="#FFFFFF"
                        stroke-width="1.5"
                    >
                        <title>{{ $item['label'] }}, semana del {{ $week['label'] }}: {{ $week['materials'][$item['key']] }} depósitos</title>
                    </circle>
                @endforeach
            @endforeach
        </svg>
    </div>

    <details class="chart-data">
        <summary>Ver tabla de datos</summary>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Depósitos por material y semana</caption>
                <thead>
                    <tr>
                        <th scope="col">Semana</th>
                        @foreach ($series as $item)
                            <th scope="col">{{ $item['label'] }}</th>
                        @endforeach
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($weeks as $week)
                        <tr>
                            <th scope="row">{{ $week['label'] }}</th>
                            @foreach ($series as $item)
                                <td>{{ $week['materials'][$item['key']] }}</td>
                            @endforeach
                            <td>{{ $week['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif

<p class="small muted chart-note">
    Cada línea es un material. Pasa el cursor sobre un punto para ver el valor de esa semana; la tabla queda disponible con el teclado.
</p>
