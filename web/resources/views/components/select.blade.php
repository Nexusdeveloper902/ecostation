@props([
    'name',
    'label',
    'options' => [],
    'selected' => null,
    'icon' => null,
    'help' => null,
    'id' => null,
    'required' => false,
])

@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.'], '-', $name);
    $hasError = $errors->has($name);
    $current = (string) old($name, $selected);
    $describedBy = $hasError ? $id.'-error' : ($help ? $id.'-help' : null);
@endphp

<div {{ $attributes->class(['field', 'field--error' => $hasError]) }}>
    <label class="field__label" for="{{ $id }}">{{ $label }}</label>

    <div class="field__control">
        @if ($icon)
            <x-icon :name="$icon" class="field__icon" />
        @endif

        <select
            id="{{ $id }}"
            name="{{ $name }}"
            @if ($required) required @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            class="select"
        >
            @foreach ($options as $value => $text)
                <option value="{{ $value }}" @selected($current === (string) $value)>{{ $text }}</option>
            @endforeach
        </select>
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
