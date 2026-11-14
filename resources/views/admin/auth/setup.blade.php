<x-admin-auth-layout>
    <h1>Set up two-factor authentication</h1><p class="admin-auth-intro">Scan this QR code with an authenticator app, then confirm the six-digit code it displays.</p>
    @if(config('app.env') === 'local' && config('admin.dashboard_preview_without_2fa'))
        <p class="admin-form-error" role="status">You can postpone authenticator setup while previewing the local dashboard.</p>
        <p><a href="{{ route('admin.dashboard') }}">Open overview</a> · <a href="{{ route('admin.billing') }}">Open payments &amp; billing</a></p>
    @endif
    <div class="admin-qr-frame"><canvas id="admin-totp-qr" data-qr-value="{{ $uri }}" aria-label="Authenticator enrollment QR code"></canvas></div>
    <details class="admin-manual-secret"><summary>Enter setup key manually</summary><code>{{ $secret }}</code></details>
    <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="admin-auth-form">@csrf
        <label for="code">Six-digit code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
        @if($errors->any())<p class="admin-form-error" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="admin-primary-button">Verify and enable</button>
    </form>
</x-admin-auth-layout>
