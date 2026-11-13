<x-admin-auth-layout>
    <h1>Admin sign in</h1><p class="admin-auth-intro">Sign in with your authorised administrator account.</p>
    <form method="POST" action="{{ route('admin.login.store') }}" class="admin-auth-form">@csrf
        <label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
        @if($errors->any())<p class="admin-form-error" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="admin-primary-button">Continue securely</button>
    </form>
</x-admin-auth-layout>
