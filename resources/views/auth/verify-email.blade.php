<x-guest-layout>
    <h1 class="mb-4 text-2xl font-light">{{ __('Verify your email') }}</h1>
    <p class="mb-4 text-sm text-gray-600">
        {{ __('Enter the six-digit code sent to') }} <strong>{{ auth()->user()->email }}</strong>.
        {{ __('The code expires after 10 minutes.') }}
    </p>
    @if (session('status') == 'verification-code-sent')
        <p class="mb-4 text-sm text-green-600" role="status">{{ __('A new verification code has been sent. Check your inbox and spam folder.') }}</p>
    @endif
    <x-input-error :messages="$errors->get('delivery')" class="mb-4" />
    <form method="POST" action="{{ route('verification.verify') }}">
        @csrf
        <x-input-label for="code" :value="__('Verification code')" />
        <x-text-input id="code" class="block mt-1 w-full" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required autofocus />
        <x-input-error :messages="$errors->get('code')" class="mt-2" />
        <x-primary-button class="mt-4">{{ __('Verify email') }}</x-primary-button>
    </form>
    <p class="mt-6 text-sm text-gray-600">{{ __('You can request a new code once per minute.') }}</p>
    <div class="mt-4 flex items-center justify-between gap-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-secondary-button type="submit">{{ __('Resend code') }}</x-secondary-button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900">{{ __('Log out') }}</button>
        </form>
    </div>
</x-guest-layout>
