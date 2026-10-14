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
    $email = 'browser-smoke-'.bin2hex(random_bytes(8)).'@example.invalid';
    $password = bin2hex(random_bytes(16));
    User::create(['name' => 'Browser Check', 'email' => $email, 'password' => $password])
        ->forceFill(['email_verified_at' => now()])->save();
    echo json_encode(['email' => $email, 'password' => $password]);
} elseif (($argv[1] ?? '') === 'delete' && preg_match('/^browser-smoke-[a-f0-9]{16}@example\.invalid$/', $argv[2] ?? '')) {
    User::where('email', $argv[2])->delete();
} else {
    throw new RuntimeException('Invalid fixture command.');
}
