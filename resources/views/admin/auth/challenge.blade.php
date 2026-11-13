<x-admin-auth-layout>
    <h1>Verify it’s you</h1><p class="admin-auth-intro">Enter a current code from your authenticator app. A recovery code can be used once if needed.</p>
    <form method="POST" action="{{ route('admin.2fa.verify') }}" class="admin-auth-form">@csrf
        <label for="code">Authentication or recovery code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32" required autofocus>
        @if($errors->any())<p class="admin-form-error" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="admin-primary-button">Verify and sign in</button>
    </form>
</x-admin-auth-layout>
