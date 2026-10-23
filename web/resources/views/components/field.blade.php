@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'icon' => null,
    'autocomplete' => null,
    'placeholder' => null,
    'required' => false,
    'autofocus' => false,
    'help' => null,
    'inputmode' => null,
    'id' => null,
])

@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.'], '-', $name);
    $hasError = $errors->has($name);
    $describedBy = $hasError ? $id.'-error' : ($help ? $id.'-help' : null);
@endphp

<div {{ $attributes->class(['field', 'field--error' => $hasError]) }}>
    <label class="field__label" for="{{ $id }}">
        {{ $label }}
    </label>

    <div class="field__control">
        @if ($icon)
            <x-icon :name="$icon" class="field__icon" />
        @endif

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($inputmode) inputmode="{{ $inputmode }}" @endif
            @if ($required) required @endif
            @if ($autofocus) autofocus @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            class="input"
        >
    </div>

    @error($name)
        <p class="field__error" id="{{ $id }}-error">
            <x-icon name="error" />
            <span>{{ $message }}</span>
        </p>
    @enderror

    @if ($help)
        <p class="field__help" id="{{ $id }}-help">{{ $help }}</p>
    @endif
</div>
