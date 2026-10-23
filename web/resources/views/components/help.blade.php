@props(['text'])

<span {{ $attributes->class(['help']) }} tabindex="0" role="note" aria-label="{{ $text }}" data-tip="{{ $text }}">?</span>
