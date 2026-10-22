@props(['href' => null])
<a href="{{ $href ?? (auth()->check() ? route('dashboard') : route('home')) }}" data-site-back {{ $attributes->merge(['class' => 'site-back-button']) }} aria-label="Go back"><span>Back</span></a>
