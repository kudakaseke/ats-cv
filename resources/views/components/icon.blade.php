@props(['name'])
<i data-lucide="{{ $name }}" {{ $attributes->merge(['class' => 'icon']) }} aria-hidden="true"></i>
