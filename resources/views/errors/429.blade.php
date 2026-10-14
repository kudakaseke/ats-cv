<x-guest-layout>
    <h1 class="mb-4 text-2xl font-light">{{ __('Please wait before trying again') }}</h1>
    <p class="mb-6 text-sm text-gray-600">
        {{ __('You have made too many requests. Please try again in') }}
        {{ max(1, (int) ceil(($exception->getHeaders()['Retry-After'] ?? 60) / 60)) }}
        {{ __('minute(s).') }}
    </p>
    <a class="underline text-sm" href="{{ auth()->check() ? route('verification.notice') : route('login') }}">{{ __('Back to your account') }}</a>
</x-guest-layout>
