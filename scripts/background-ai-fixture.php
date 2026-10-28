<?php

// Local browser verification only: complete a disposable user's queued job with mocked AI.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('local') || ! preg_match('/^browser-smoke-[a-f0-9]{16}@example\.invalid$/', $argv[1] ?? '')) {
    throw new RuntimeException('Restricted to disposable local browser fixtures.');
}
$user = App\Models\User::where('email', $argv[1])->firstOrFail();
$operationId = (int) ($argv[2] ?? 0);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(['api.moonshot.ai/*' => Illuminate\Support\Facades\Http::response([
    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
        'summary' => 'Kimi background fixture completed.', 'recommendations' => ['Keep source-evidenced skills visible.'],
    ])]]],
    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
])]);
foreach (Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'ai')->get() as $row) {
    $payload = json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR);
    $command = Illuminate\Support\Facades\Crypt::decrypt($payload['data']['command']);
    $job = unserialize($command);
    if (! $job instanceof App\Jobs\ProcessCvAi || $job->userId !== $user->id || $job->operationId !== $operationId) {
        continue;
    }
    $job->handle();
    Illuminate\Support\Facades\DB::table('jobs')->where('id', $row->id)->delete();
    Illuminate\Support\Facades\Http::assertSentCount(1);
    echo json_encode(['completed' => true, 'provider_calls' => 0, 'mocked_requests' => 1]);
    exit;
}
throw new RuntimeException('No matching fixture job.');
