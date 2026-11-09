<?php

namespace App\Models;

use App\Jobs\SendPasswordResetMail;
use App\Services\AiCreditService;
use App\Services\EmailVerificationCodeService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    protected static function booted(): void
    {
        static::created(fn (User $user) => app(AiCreditService::class)->wallet($user));
    }

    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationCodeService::class)->send($this);
    }

    public function sendPasswordResetNotification($token): void
    {
        SendPasswordResetMail::dispatch($this->id, $token);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(CvAnalysis::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code_hash',
        'email_verification_code_email',
        'email_verification_code_expires_at',
        'email_verification_code_attempts',
    ];

    public function canSetInitialPassword(): bool
    {
        return (bool) $this->google_id && $this->password_setup_required && ! $this->password_set_at;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
            'password_setup_required' => 'boolean',
            'email_verification_code_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
