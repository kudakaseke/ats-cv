<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Browser fixtures are restricted to local development.');
}

if (($argv[1] ?? '') === 'create') {
    $domain = ($argv[2] ?? '') === 'payment' ? 'example.com' : 'example.invalid';
    $email = 'browser-smoke-'.bin2hex(random_bytes(8)).'@'.$domain;
    $password = bin2hex(random_bytes(16));
    $user = User::create(['name' => 'Browser Check', 'email' => $email, 'password' => $password]);
    $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
    if (in_array('pro', $argv, true)) {
        $user->subscriptions()->create(['subscription_plan_id' => App\Models\SubscriptionPlan::where('slug', 'pro')->firstOrFail()->id,
            'status' => 'active', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMonth()]);
    }
    echo json_encode(['email' => $email, 'password' => $password]);
} elseif (($argv[1] ?? '') === 'delete' && preg_match('/^browser-smoke-[a-f0-9]{16}@example\.(invalid|com)$/', $argv[2] ?? '')) {
    User::where('email', $argv[2])->delete();
} else {
    throw new RuntimeException('Invalid fixture command.');
}
