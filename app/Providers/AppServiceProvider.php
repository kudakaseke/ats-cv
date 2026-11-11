<?php

namespace App\Providers;

use App\Models\BillingPayment;
use App\Models\CvAnalysis;
use App\Models\Resume;
use App\Models\Subscription;
use App\Models\User;
use App\Observers\ActivityObserver;
use App\Services\AuditTrail;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap application rate limits.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        RateLimiter::for('ingress', function (Request $request) {
            $ip = $request->ip();
            if ($request->isMethod('POST') && $request->is('billing/paynow/notify/*')) {
                return [Limit::perSecond(100)->by('paynow-ingress-burst:'.$ip),
                    Limit::perMinute(3000)->by('paynow-ingress-minute:'.$ip)];
            }
            $limits = [
                Limit::perSecond(60)->by('ingress-burst:'.$ip),
                Limit::perMinute(600)->by('ingress-minute:'.$ip),
            ];
            if ($request->isMethod('GET') && $request->is('login', 'register', 'forgot-password', 'reset-password/*', 'pricing')) {
                $limits[] = Limit::perMinute(60)->by('ingress-public:'.$ip);
            }

            return $limits;
        });
        RateLimiter::for('oauth', fn (Request $request) => [
            Limit::perMinute(20)->by('oauth-minute:'.$request->ip()),
            Limit::perHour(60)->by('oauth-hour:'.$request->ip()),
        ]);
        RateLimiter::for('site', fn (Request $request) => $request->isMethod('POST') && $request->is('billing/paynow/notify/*')
            ? Limit::none() : Limit::perMinute(600)->by('site-ip:'.$request->ip()));
        RateLimiter::for('workspace-read', fn (Request $request) => [
            Limit::perMinute(120)->by('workspace-user:'.$request->user()->id),
            Limit::perMinute(600)->by('workspace-ip:'.$request->ip()),
        ]);
        RateLimiter::for('payment-return', fn (Request $request) => [
            Limit::perMinute(20)->by('payment-user:'.$request->user()->id),
            Limit::perMinute(600)->by('payment-ip:'.$request->ip()),
        ]);
        RateLimiter::for('payment-checkout', fn (Request $request) => [
            Limit::perMinute(6)->by('checkout-user:'.$request->user()->id),
            Limit::perHour(30)->by('checkout-hour:'.$request->user()->id),
            Limit::perMinute(120)->by('checkout-ip:'.$request->ip()),
        ]);
        RateLimiter::for('payment-check', fn (Request $request) => [
            Limit::perMinute(12)->by('check-user:'.$request->user()->id),
            Limit::perMinute(300)->by('check-ip:'.$request->ip()),
        ]);
        RateLimiter::for('payment-notify', fn (Request $request) => [
            Limit::perMinute(60)->by('notify-reference:'.hash('sha256', (string) $request->route('reference'))),
            Limit::perMinute(3000)->by('notify-ip:'.$request->ip()),
        ]);
        RateLimiter::for('cv-export', fn (Request $request) => [
            Limit::perMinute(5)->by('export-user:'.$request->user()->id),
            Limit::perMinute(20)->by('export-ip:'.$request->ip()),
        ]);
        RateLimiter::for('ai-analysis', fn (Request $request) => [
            Limit::perMinute(6)->by('analysis-user:'.$request->user()->id),
            Limit::perMinute(30)->by('analysis-ip:'.$request->ip()),
        ]);
        foreach ([BillingPayment::class, Subscription::class, User::class, Resume::class, CvAnalysis::class] as $model) {
            $model::observe(ActivityObserver::class);
        }
        Event::listen(Login::class, function ($event) {
            app(\App\Services\AccountSessions::class)->stamp($event->user);
        });
        foreach ([Login::class, Logout::class, Failed::class, PasswordReset::class, Verified::class] as $event) {
            Event::listen($event, function ($activity) {
                app(AuditTrail::class)->record($activity->user?->id,
                    'account.'.strtolower(class_basename($activity)), 'User', $activity->user?->id ?? 'unknown');
            });
        }

        if (PHP_OS_FAMILY === 'Windows' && $this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_values(array_unique([
                ...ServeCommand::$passthroughVariables,
                'SystemRoot', 'SystemDrive', 'windir', 'WINDIR', 'ComSpec', 'COMSPEC',
                'TEMP', 'TMP', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA',
                'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'HOMEDRIVE',
            ]));
        }
        RateLimiter::for('builder-save', fn (Request $request) => [
            Limit::perMinute(90)->by('session:'.$request->session()->getId()),
            Limit::perMinute(180)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('builder-import', fn (Request $request) => [
            Limit::perMinute(3)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('auth-login', function (Request $request) {
            $email = hash('sha256', strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')));

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
            $email = hash('sha256', strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')));

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
