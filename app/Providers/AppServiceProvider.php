<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap application rate limits.
     */
    public function boot(): void
    {
        RateLimiter::for('builder-save', fn (Request $request) => [
            Limit::perMinute(90)->by('session:'.$request->session()->getId()),
            Limit::perMinute(180)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('builder-import', fn (Request $request) => [
            Limit::perMinute(3)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('auth-login', function (Request $request) {
            $email = hash('sha256', strtolower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(20)->by('ip-minute:'.$request->ip()),
                Limit::perHour(100)->by('ip-hour:'.$request->ip()),
                Limit::perMinutes(15, 10)->by('email:'.$email),
            ];
        });

        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(3)->by('ip-minute:'.$request->ip()),
            Limit::perHour(10)->by('ip-hour:'.$request->ip()),
        ]);

        RateLimiter::for('auth-reset', function (Request $request) {
            $email = hash('sha256', strtolower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(3)->by('ip-minute:'.$request->ip()),
                Limit::perHour(10)->by('ip-hour:'.$request->ip()),
                Limit::perHour(3)->by('email-hour:'.$email),
                Limit::perDay(5)->by('email-day:'.$email),
            ];
        });

        RateLimiter::for('verification-send', fn (Request $request) => [
            Limit::perMinute(1)->by('user:'.$request->user()->id),
            Limit::perHour(10)->by('ip-hour:'.$request->ip()),
            Limit::perDay(30)->by('ip-day:'.$request->ip()),
        ]);

        RateLimiter::for('verification-check', fn (Request $request) => [
            Limit::perMinute(6)->by('user-minute:'.$request->user()->id),
            Limit::perHour(20)->by('user-hour:'.$request->user()->id),
            Limit::perMinute(30)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth-password', fn (Request $request) => [
            Limit::perMinute(5)->by('identity:'.($request->user()?->id ?? $request->ip())),
            Limit::perHour(20)->by('ip:'.$request->ip()),
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
