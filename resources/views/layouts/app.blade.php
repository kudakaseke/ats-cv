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
    <div class="workspace-shell" x-data="workspaceNavigation()" @resize.window.debounce="resize()" @keydown.window="handleKey($event)">
        <div class="sidebar-overlay" x-cloak x-show="sidebar" @click="closeSidebar()"></div>
        <aside x-ref="navigation" :role="mobile && sidebar ? 'dialog' : null" :aria-modal="mobile && sidebar ? 'true' : null" aria-label="Workspace menu" id="workspace-navigation" class="workspace-sidebar" :class="{ 'sidebar-open': sidebar }" :inert="mobile && !sidebar ? '' : null">
            @php($sidebarPlan = app(\App\Services\PlanService::class)->current(auth()->user()))
            <a class="workspace-brand" href="{{ route('dashboard') }}" aria-label="ATS CV Detector home"><x-logo-image /><span class="sidebar-current-plan">{{ $sidebarPlan->name }} plan</span></a>
            <button type="button" class="sidebar-close-button" x-cloak x-show="mobile" @click="closeSidebar()" aria-label="Close sidebar"><x-icon name="arrow-left" />Close menu</button>
            <nav aria-label="Workspace navigation">
                @foreach ([
                    'Overview' => [['dashboard', 'house', 'Home', route('dashboard')]],
                    'CV Analysis' => [['checker', 'scan-text', 'Job-to-CV ATS Detector', route('workspace.module', 'checker')], ['detect', 'file-check-2', 'ATS Checker', route('workspace.module', 'detect')], ['history', 'history', 'Saved checks', route('workspace.module', 'history')]],
                    'CV Toolkit' => [['builder', 'file-plus-2', 'Create CV', route('workspace.module', 'builder')], ['resumes', 'folder-open', 'My CVs', route('workspace.module', 'resumes')], ['drafts', 'file-pen-line', 'Drafts', route('workspace.module', 'drafts')]],
                    'Account' => [['subscription', 'credit-card', 'Subscription', route('subscription')], ['profile', 'user-round', 'Account settings', route('profile.edit')], ['account.activity', 'history', 'Audit trail', route('account.activity')]],
                ] as $group => $items)
                    <div class="sidebar-nav-group" role="group" aria-label="{{ $group }}">
                        <h2 class="sidebar-group-heading">{{ $group }}</h2>
                        @foreach($items as $item)
                            @php($active = request()->routeIs($item[0], $item[0].'.*') || request()->route('module') === $item[0])
                            <a class="sidebar-link {{ $active ? 'active' : '' }}" href="{{ $item[3] }}" @click="closeSidebar()" @if($active) aria-current="page" @endif><x-icon :name="$item[1]" />{{ $item[2] }}</a>
                        @endforeach
                    </div>
                @endforeach
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="sidebar-logout">
                @csrf
                <button class="sidebar-link" type="submit"><x-icon name="log-out" />Sign out</button>
            </form>
        </aside>
        <div class="workspace-main" :inert="mobile && sidebar ? '' : null">
            <header class="workspace-topbar">
                <button class="mobile-menu-button" x-ref="menuToggle" @click="openSidebar()" :aria-expanded="sidebar" aria-controls="workspace-navigation" aria-label="Toggle sidebar"><x-icon name="menu" /></button>
                @php($headerWallet = app(\App\Services\AiCreditService::class)->displayWallet(auth()->user()))
                <a class="purchased-credit-badge" href="{{ route('subscription.payment-methods', 'monthly') }}"
                    x-data="{ available: @js($headerWallet['credits']), checking: false,
                        async refreshCredits() {
                            if (this.checking) return;
                            this.checking = true;
                            try {
                                const response = await fetch(@js(route('ai.credits')), { headers: { Accept: 'application/json' } });
                                if (response.ok) { const wallet = await response.json(); this.available = wallet.credits; }
                            } catch (error) { /* Keep the last known balance. */ }
                            finally { this.checking = false; }
                        }
                    }"
                    @focus.window="refreshCredits()" @credits-updated.window="refreshCredits()"
                    :aria-label="available + ' credits available. Top up credits.'"
                    title="Available credits remaining">
                    <strong x-text="available">{{ $headerWallet['credits'] }}</strong>
                    <span>Credits</span>
                    <span x-show="available <= 20" @if($headerWallet['credits'] > 20) x-cloak @endif>Top up</span>
                </a>
                <a class="user-profile" href="{{ route('profile.edit') }}" aria-label="Account settings for {{ auth()->user()->name }}">
                    <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span>{{ auth()->user()->name }}<small>Account settings</small></span>
                </a>
            </header>
            <main id="main" class="workspace-content">
                @if(request()->routeIs('dashboard'))<x-input-error :messages="$errors->get('billing')" class="my-4" />@endif
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
