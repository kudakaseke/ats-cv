@props(['title' => 'Your next career move starts here'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Understand your CV, express your experience, and prepare for your next career move with atscvdetector">
    <title>{{ $title }} · atscvdetector</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-site">
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="reference-header {{ request()->routeIs('home') ? 'blue-header' : '' }}" x-data="{ open: false }" @keydown.escape.window="open = false">
        <div class="reference-nav">
            <a class="reference-wordmark" href="{{ route('home') }}" aria-label="atscvdetector home">atscvdetector</a>
            <nav class="reference-desktop-nav" aria-label="Main navigation">
                <a href="{{ route('home') }}">Home</a>
                <a href="{{ route('home') }}#about">About</a>
                <a href="{{ route('ats.explained') }}">ATS Explained</a><a href="{{ route('home') }}#features">Features</a>
                <a href="{{ route('home') }}#pricing">Pricing</a>
            </nav>
            <div class="reference-account-nav">
                @auth
                    <a href="{{ route('dashboard') }}">My Dashboard</a>
                @else
                    <a href="{{ route('login') }}">Sign In</a>
                    <a class="reference-signup" href="{{ route('register') }}">Sign Up</a>
                @endauth
            </div>
            <button class="reference-menu-button" @click="open = !open" :aria-expanded="open" aria-controls="mobile-nav" aria-label="Toggle navigation"><span>Menu</span><span class="reference-menu-lines" aria-hidden="true"><span></span><span></span><span></span></span></button>
        </div>
        <nav id="mobile-nav" x-show="open" x-cloak class="reference-expanded-nav" aria-label="Expanded navigation">
            <a @click="open = false" href="{{ route('home') }}">Home</a>
            <a @click="open = false" href="{{ route('home') }}#about">About</a>
            <a @click="open = false" href="{{ route('ats.explained') }}">ATS Explained</a><a @click="open = false" href="{{ route('home') }}#features">Features</a>
            <a @click="open = false" href="{{ route('home') }}#pricing">Pricing</a>
            @auth<a href="{{ route('dashboard') }}">My Dashboard</a>@else<a href="{{ route('login') }}">Sign In</a><a href="{{ route('register') }}">Sign Up</a>@endauth
        </nav>
    </header>
    @unless(request()->routeIs('home'))
        <div class="public-back-row"><x-back-button /></div>
    @endunless
    <main id="main">{{ $slot }}</main>
    <footer class="reference-footer">
        <div class="reference-container">
            <div class="reference-footer-top"><div><a class="reference-wordmark" href="{{ route('home') }}">atscvdetector</a><p>Your experience.<br>Your next opportunity.</p></div><nav aria-label="Footer navigation"><a href="{{ route('home') }}">Home</a><a href="{{ route('home') }}#about">About</a><a href="{{ route('ats.explained') }}">ATS Explained</a><a href="{{ route('home') }}#features">Features</a><a href="{{ route('home') }}#pricing">Pricing</a><a href="{{ route('contact') }}">Contact</a></nav></div>
            <div class="reference-footer-bottom"><span>© {{ date('Y') }} atscvdetector · Code Dev Solutions</span><div><a href="{{ route('privacy') }}">Privacy Policy</a><a href="{{ route('terms') }}">Terms and Conditions</a><a href="{{ route('refund') }}">Refund Policy</a></div></div>
        </div>
    </footer>
</body>
</html>
