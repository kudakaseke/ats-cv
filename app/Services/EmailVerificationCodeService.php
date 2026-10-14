<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailVerificationCodeService
{
    public function send(User $user): void
    {
        if ($user->fresh()->hasVerifiedEmail()) {
            return;
        }
        $recipient = hash('sha256', strtolower(trim($user->fresh()->email)));
        try {
            Cache::lock('verification-send-lock:'.$recipient, 10)->block(3, function () use ($recipient) {
                $limits = [
                    ['minute', 1, 60],
                    ['hour', 5, 3600],
                    ['day', 10, 86400],
                ];
                foreach ($limits as [$window, $maximum, $seconds]) {
                    $key = 'verification-mail:'.$window.':'.$recipient;
                    if (RateLimiter::tooManyAttempts($key, $maximum)) {
                        $wait = RateLimiter::availableIn($key);
                        throw ValidationException::withMessages([
                            'delivery' => 'Too many code requests. Please try again in '.max(1, (int) ceil($wait / 60)).' minute(s).',
                        ]);
                    }
                }
                foreach ($limits as [$window, $maximum, $seconds]) {
                    RateLimiter::hit('verification-mail:'.$window.':'.$recipient, $seconds);
                }
            });
        } catch (LockTimeoutException $exception) {
            throw ValidationException::withMessages(['delivery' => 'Another code request is being processed. Please wait before trying again.']);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($user, $code) {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $account->forceFill([
                'email_verification_code_hash' => Hash::make($code),
                'email_verification_code_email' => $account->email,
                'email_verification_code_expires_at' => now()->addMinutes(10),
                'email_verification_code_attempts' => 0,
            ])->save();
            $account->notify(new VerifyEmailCode($code));
        });
    }

    public function verify(User $user, string $code): void
    {
        $result = DB::transaction(function () use ($user, $code) {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($account->hasVerifiedEmail()) {
                return 'verified';
            }
            if (! $account->email_verification_code_hash
                || $account->email_verification_code_email !== $account->email
                || ! $account->email_verification_code_expires_at
                || $account->email_verification_code_expires_at->isPast()) {
                return 'This code has expired or is unavailable. Please request a new code.';
            }
            if ($account->email_verification_code_attempts >= 5) {
                return 'Too many incorrect attempts. Please request a new code.';
            }
            if (! Hash::check($code, $account->email_verification_code_hash)) {
                $account->increment('email_verification_code_attempts');

                return 'The verification code is incorrect.';
            }
            $account->forceFill([
                'email_verified_at' => now(),
                'email_verification_code_hash' => null,
                'email_verification_code_email' => null,
                'email_verification_code_expires_at' => null,
                'email_verification_code_attempts' => 0,
            ])->save();

            return $account;
        });
        if ($result instanceof User) {
            $user->refresh();
            event(new Verified($result));
        } elseif ($result !== 'verified') {
            throw ValidationException::withMessages(['code' => $result]);
        }
    }
}
