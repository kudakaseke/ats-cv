<x-admin-auth-layout>
    <h1>Check your email</h1><p class="admin-auth-intro">Enter the six-digit sign-in code sent to <strong>{{ $maskedEmail }}</strong>. It expires in 10 minutes.</p>
    @if(session('status'))<p class="admin-form-status" role="status">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('admin.2fa.verify') }}" class="admin-auth-form">@csrf
        <label for="code">Six-digit email code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
        @if($errors->any())<p class="admin-form-error" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="admin-primary-button">Verify and sign in</button>
    </form>
    <form method="POST" action="{{ route('admin.2fa.resend') }}" class="admin-auth-form">@csrf<button type="submit" class="admin-secondary-button">Send a new code</button></form>
</x-admin-auth-layout>
