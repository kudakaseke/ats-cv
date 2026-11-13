<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin overview' }} · ATS CV Detector</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-body">
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Super admin navigation">
            <a class="admin-brand" href="{{ route('admin.dashboard') }}"><x-logo-image /><span>SUPER ADMIN</span></a>
            <div class="admin-sidebar-label">PLATFORM</div>
            <nav>
                <a href="{{ route('admin.dashboard') }}" @class(['admin-nav-link', 'active' => request()->routeIs('admin.dashboard')])><x-icon name="chart-no-axes-combined" />Overview</a>
                <a href="{{ route('admin.billing') }}" @class(['admin-nav-link', 'active' => request()->routeIs('admin.billing*')])><x-icon name="credit-card" />Payments & billing</a>
            </nav>
            <div class="admin-sidebar-bottom"><span>Private operations</span><span class="admin-secure-dot">● Two-factor protected</span></div>
        </aside>
        <main class="admin-main">
            <header class="admin-topbar"><div><span class="admin-kicker">ATS CV DETECTOR</span><span class="admin-top-title">Super Admin Dashboard</span></div>
                <div class="admin-user-area"><span class="admin-private-pill">Private</span><span>{{ auth('admin')->user()->name }}</span>
                    <form method="POST" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="admin-signout">Sign out</button></form>
                </div>
            </header>
            @if(session('status'))<p class="admin-flash" role="status">{{ session('status') }}</p>@endif
            {{ $slot }}
            <footer class="admin-footer">Private administrator access · {{ now(config('app.timezone'))->format('d M Y H:i T') }}</footer>
        </main>
    </div>
</body>
</html>
