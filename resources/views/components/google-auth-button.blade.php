<div class="mb-6">
    <a data-google-auth href="{{ route('auth.google') }}" class="mx-auto flex w-fit max-w-full items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
        <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.39-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-2 3.02v2.51h3.24c1.9-1.75 2.98-4.33 2.98-7.36Z"/>
            <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.41l-3.24-2.51c-.9.6-2.05.96-3.38.96-2.6 0-4.8-1.76-5.59-4.12H3.07v2.59A10 10 0 0 0 12 22Z"/>
            <path fill="#FBBC05" d="M6.41 13.92a6 6 0 0 1 0-3.84V7.49H3.07a10 10 0 0 0 0 9.02l3.34-2.59Z"/>
            <path fill="#EA4335" d="M12 5.96c1.47 0 2.79.5 3.82 1.49l2.87-2.87A9.6 9.6 0 0 0 12 2a10 10 0 0 0-8.93 5.49l3.34 2.59C7.2 7.72 9.4 5.96 12 5.96Z"/>
        </svg>
        <span data-navigation-label>{{ __('Continue with Google') }}</span>
    </a>
    <x-input-error :messages="$errors->get('google')" class="mt-2" />
    <div class="mt-5 flex items-center gap-3 text-xs text-gray-500">
        <span class="h-px flex-1 bg-gray-200"></span>
        <span>{{ __('or continue with email') }}</span>
        <span class="h-px flex-1 bg-gray-200"></span>
    </div>
</div>
