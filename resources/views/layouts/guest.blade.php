<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} · Your account</title>
    <link rel="icon" href="{{ asset('favicon.png').'?v=minimal-cv-3' }}" type="image/png"><link rel="icon" href="{{ asset('favicon.svg').'?v=minimal-cv-3' }}" type="image/svg+xml" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-body auth-clean">
    <div class="auth-waves" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="auth-shell">
        <a class="site-back-button auth-site-back" href="{{ route('home') }}"><span>Home</span></a>
        <header class="auth-clean-brand"><x-brand /></header>
        <main class="auth-content">
            <div class="auth-card">
                <h1>{{ request()->routeIs('register') ? 'Create your account' : (request()->routeIs('login') ? 'Sign in' : 'Your account') }}</h1>
                <div class="auth-form-content">{{ $slot }}</div>
                @if(request()->routeIs('login'))
                    <div class="auth-card-bottom"><p class="auth-switch">Don’t have an account? <a href="{{ route('register') }}">Sign up</a></p></div>
                @elseif(request()->routeIs('register'))
                    <div class="auth-card-bottom"><p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p><p class="auth-legal">By registering, you agree to our <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('privacy') }}">Privacy Policy</a>.</p></div>
                @endif
            </div>
        </main>
        <footer class="auth-footer"><a href="{{ route('contact') }}">Support</a><span>·</span><a href="{{ route('terms') }}">Terms</a><span>·</span><a href="{{ route('privacy') }}">Privacy</a></footer>
    </div>
</body>
</html>
