<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} · Workspace</title>
    <link rel="icon" href="{{ asset('favicon.png').'?v=minimal-cv-3' }}" type="image/png"><link rel="icon" href="{{ asset('favicon.svg').'?v=minimal-cv-3' }}" type="image/svg+xml" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body data-workspace>
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="workspace-shell" x-data="{ sidebar: false, mobile: window.innerWidth <= 900 }" @resize.window.debounce="mobile = window.innerWidth <= 900; if (!mobile) sidebar = false" @keydown.escape.window="sidebar = false">
        <div class="sidebar-overlay" x-cloak x-show="sidebar" @click="sidebar = false"></div>
        <aside id="workspace-navigation" class="workspace-sidebar" :class="{ 'sidebar-open': sidebar }" :inert="mobile && !sidebar ? '' : null">
            <a class="workspace-brand" href="{{ route('dashboard') }}" aria-label="ATS CV Detector dashboard"><x-logo-image /></a>
            <nav aria-label="Workspace navigation">
                @foreach ([['dashboard', 'layout-dashboard', 'Dashboard', route('dashboard')], ['checker', 'scan-text', 'Job-to-CV ATS Detector', route('workspace.module', 'checker')], ['detect', 'file-check-2', 'ATS Checker', route('workspace.module', 'detect')], ['builder', 'file-plus-2', 'Create CV', route('workspace.module', 'builder')], ['resumes', 'folder-open', 'My CVs', route('workspace.module', 'resumes')], ['subscription', 'credit-card', 'Subscription', route('subscription')]] as $item)
                    @php($active = request()->routeIs($item[0]) || request()->route('module') === $item[0])
                    <a class="sidebar-link {{ $active ? 'active' : '' }}" href="{{ $item[3] }}" @click="sidebar = false" @if($active) aria-current="page" @endif><x-icon :name="$item[1]" />{{ $item[2] }}</a>
                @endforeach
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="sidebar-logout">
                @csrf
                <button class="sidebar-link" type="submit"><x-icon name="log-out" />Sign out</button>
            </form>
        </aside>
        <div class="workspace-main">
            <header class="workspace-topbar">
                <button class="mobile-menu-button" @click="sidebar = !sidebar" :aria-expanded="sidebar" aria-controls="workspace-navigation" aria-label="Toggle sidebar"><x-icon name="menu" /></button>
                @php($headerWallet = app(\App\Services\AiCreditService::class)->displayWallet(auth()->user()))
                <a class="purchased-credit-badge" href="{{ route('subscription') }}"
                    x-data="{ credits: @js($headerWallet['purchased_credits']), checking: false,
                        async refreshCredits() {
                            if (this.checking) return;
                            this.checking = true;
                            try {
                                const response = await fetch(@js(route('ai.credits')), { headers: { Accept: 'application/json' } });
                                if (response.ok) this.credits = (await response.json()).purchased_credits;
                            } catch (error) { /* Keep the last known balance. */ }
                            finally { this.checking = false; }
                        }
                    }"
                    @focus.window="refreshCredits()" @credits-updated.window="refreshCredits()"
                    :aria-label="credits + ' purchased credits remaining. View your plan.'"
                    title="Purchased credits remaining">
                    <strong x-text="credits">{{ $headerWallet['purchased_credits'] }}</strong>
                    <span>Credits</span>
                </a>
                <a class="user-profile" href="{{ route('profile.edit') }}" aria-label="Account settings for {{ auth()->user()->name }}">
                    <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span>{{ auth()->user()->name }}<small>Account settings</small></span>
                </a>
            </header>
            <main id="main" class="workspace-content">
                @unless(request()->routeIs('dashboard'))
                    <div class="workspace-back-row"><x-back-button /></div>
                @endunless
                @isset($header)<div class="workspace-page-header">{{ $header }}</div>@endisset
                {{ $slot }}
            </main>
            <footer class="workspace-footer">
                <span>© {{ date('Y') }} atscvdetector</span>
                <div><a href="{{ route('privacy') }}">Privacy</a><a href="{{ route('terms') }}">Terms</a><a href="{{ route('refund') }}">Refunds</a></div>
            </footer>
        </div>
    </div>
    @livewireScripts
    <x-cookie-notice />
</body>
</html>
