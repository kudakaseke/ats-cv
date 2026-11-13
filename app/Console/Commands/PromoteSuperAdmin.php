<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteSuperAdmin extends Command
{
    protected $signature = 'admin:promote {email : Email of an existing verified user}';
    protected $description = 'Grant the super_admin role to an existing verified account';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
        if (! $user || ! $user->email_verified_at) {
            $this->error('No verified account exists for that email.');

            return self::FAILURE;
        }
        if (! $this->confirm('Grant super_admin access to '.$user->email.'? This enables access to all platform data.')) {
            return self::SUCCESS;
        }
        $user->forceFill(['role' => 'super_admin', 'is_admin' => true, 'account_status' => 'active'])->save();
        $this->info('Super admin role granted. The user must enroll an authenticator at /admin/login.');

        return self::SUCCESS;
    }
}
