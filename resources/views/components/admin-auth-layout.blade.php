<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title ?? 'Admin sign in' }} · ATS CV Detector</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head><body class="admin-auth-body"><main class="admin-auth-card"><a class="admin-auth-brand" href="{{ route('home') }}"><x-logo-image /></a><p class="admin-kicker">PRIVATE ADMIN PORTAL</p>{{ $slot }}<p class="admin-auth-footnote">Administrator access is monitored and protected with two-factor authentication.</p></main></body></html>
