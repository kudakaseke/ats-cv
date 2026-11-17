<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Notifications\AdminLoginCode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AdminLoginCodeService
{
    public function issue(User $user): void
    {
        $recipientKey = hash('sha256', strtolower(trim($user->email)));
        $limits = [
            ['minute', 2, 60],
            ['hour', 5, 3600],
        ];

        try {
            Cache::lock('admin-login-code-send-lock:'.$recipientKey, 120)->block(5, function () use ($recipientKey, $limits, $user) {
                foreach ($limits as [$window, $maximum]) {
                    $key = 'admin-login-code-send:'.$window.':'.$recipientKey;
                    if (RateLimiter::tooManyAttempts($key, $maximum)) {
                        $wait = RateLimiter::availableIn($key);
                        throw ValidationException::withMessages([
                            'code' => 'Too many sign-in codes were requested. Please wait '.max(1, (int) ceil($wait / 60)).' minute(s) before trying again.',
                        ]);
                    }
                }

                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $key = 'admin-login-code:'.$user->id;
                Cache::put($key, ['hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)->timestamp], now()->addMinutes(10));
                Cache::put($key.':attempts', 0, now()->addMinutes(10));

                try {
                    $user->notify(new AdminLoginCode($user->id, $code));
                } catch (\Throwable $exception) {
                    Cache::forget($key);
                    Cache::forget($key.':attempts');
                    $message = str_replace(array_filter([
                        config('mail.mailers.smtp.username'),
                        config('mail.mailers.smtp.password'),
                        config('admin.owner_email'),
                    ]), '[REDACTED]', $exception->getMessage());
                    Log::error('Admin sign-in code email delivery failed.', [
                        'exception' => $exception::class,
                        'message' => $message,
                    ]);
                    throw ValidationException::withMessages(['email' => 'The sign-in code could not be sent. Check the mail service configuration and try again.']);
                }

                foreach ($limits as [$window, $maximum, $seconds]) {
                    RateLimiter::hit('admin-login-code-send:'.$window.':'.$recipientKey, $seconds);
                }
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['code' => 'Another sign-in code is being sent. Please wait and try again.']);
        }
    }

    public function verify(User $user, string $code): void
    {
        try {
            $result = Cache::lock('admin-login-code-verify-lock:'.$user->id, 10)->block(3, function () use ($user, $code) {
                $key = 'admin-login-code:'.$user->id;
                $challenge = Cache::get($key);
                if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) < now()->timestamp) {
                    return 'This sign-in code has expired. Request a new code.';
                }

                $attemptsKey = $key.':attempts';
                $attempts = (int) Cache::get($attemptsKey, 0);
                if ($attempts >= 5) {
                    Cache::forget($key);

                    return 'Too many incorrect codes. Request a new sign-in code.';
                }
                if (! Hash::check($code, $challenge['hash'] ?? '')) {
                    Cache::increment($attemptsKey);

                    return 'The sign-in code is incorrect.';
                }

                Cache::forget($key);
                Cache::forget($attemptsKey);

                return null;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['code' => 'Code verification is busy. Please try again.']);
        }

        if ($result !== null) {
            throw ValidationException::withMessages(['code' => $result]);
        }
    }

    public function invalidate(User $user): void
    {
        Cache::forget('admin-login-code:'.$user->id);
        Cache::forget('admin-login-code:'.$user->id.':attempts');
    }
}
