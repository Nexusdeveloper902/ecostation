@props(['name' => '', 'size' => null, 'tone' => null])

@php
    $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';

    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }

    $initials = $initials !== '' ? $initials : '·';
    $palette = ['sun', 'mint', 'blue', 'coral', 'turquoise', 'pink'];
    $resolvedTone = $tone ?? $palette[abs(crc32(mb_strtolower((string) $name))) % count($palette)];
    $classes = array_filter(['avatar', $size ? 'avatar--'.$size : null, $resolvedTone !== 'sun' ? 'avatar--'.$resolvedTone : null]);
@endphp

<span {{ $attributes->class($classes) }} aria-hidden="true">{{ $initials }}</span>
