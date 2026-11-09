<x-app-layout>
    <div class="dashboard-heading"><div><h1>Set a password</h1></div></div>
    <div class="panel workspace-settings-panel">
        <form method="POST" action="{{ route('password.setup.store') }}" class="space-y-6">
            @csrf
            <div>
                <x-input-label for="setup_email" value="Email" />
                <x-text-input id="setup_email" class="mt-1 block w-full" type="email" name="email" :value="$user->email" readonly autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="setup_password" value="Password" />
                <x-text-input id="setup_password" class="mt-1 block w-full" type="password" name="password" required autofocus autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="setup_password_confirmation" value="Confirm password" />
                <x-text-input id="setup_password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>
            <x-primary-button>Set password</x-primary-button>
        </form>
    </div>
</x-app-layout>
