<x-app-layout>
    <div class="dashboard-heading"><div><h1>Account settings</h1><p>Manage your personal information, password and account.</p></div></div>
    <div class="workspace-settings">
        <x-input-error :messages="$errors->get('google')" class="mt-2" />
        @if(auth()->user()->google_id && !auth()->user()->password_set_at)
            <div class="panel workspace-settings-panel"><p>If you signed up with Google and have not set a password, <a class="text-link" href="{{ route('password.setup') }}">set a password</a> before changing your password or confirming account deletion.</p></div>
        @endif
        <div class="panel workspace-settings-panel">@include('profile.partials.update-profile-information-form')</div>
        @if(!$user->google_id || $user->password_set_at)
            <div class="panel workspace-settings-panel">@include('profile.partials.update-password-form')</div>
        @endif
        <div class="panel workspace-settings-panel workspace-danger-panel">@include('profile.partials.delete-user-form')</div>
    </div>
</x-app-layout>
