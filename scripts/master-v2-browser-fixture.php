<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('local')) throw new RuntimeException('Local verification only.');
$fixture = Tests\Fixtures\MasterCvV2::class;
$jobDescription = 'PHP and mandatory AWS certification';
Illuminate\Support\Facades\Http::preventStrayRequests();
if (($argv[1] ?? '') === 'input') {
    $assessment = app(App\Services\CvAiAssessment::class)->normalise(
        app(App\Services\CvAiV2Report::class)->sourceGuard($fixture::assessment(), $fixture::SOURCE));
    $scan = app(App\Services\CvKeywordMatcher::class)->compare($jobDescription, $fixture::SOURCE);
    $scan += ['ai_analysis' => ['summary' => 'PHP is supported; AWS certification is not evidenced.', 'recommendations' => $assessment['recommendations'], 'assessment' => $assessment],
        'assessment' => $assessment, 'credits_remaining' => 15];
    echo json_encode(['job' => $jobDescription, 'resume' => $fixture::SOURCE, 'scan' => app(App\Services\CvCompatibilityScore::class)->scan($scan)]);
    exit;
}
if (($argv[1] ?? '') !== 'complete' || ! preg_match('/^browser-smoke-[a-f0-9]{16}@example\.invalid$/', $argv[2] ?? '')) {
    throw new RuntimeException('Restricted to disposable browser accounts.');
}
$user = App\Models\User::where('email', $argv[2])->firstOrFail();
$operationId = (int) ($argv[3] ?? 0);
foreach (Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'ai')->get() as $row) {
    $payload = json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR);
    $worker = unserialize(Illuminate\Support\Facades\Crypt::decrypt($payload['data']['command']));
    if (! $worker instanceof App\Jobs\ProcessCvAi || $worker->userId !== $user->id || $worker->operationId !== $operationId) continue;
    if ($worker->provider !== 'openai') throw new RuntimeException('Expected OpenAI worker.');
    $analysis = $fixture::response(['summary' => 'Review complete.', 'recommendations' => [], 'assessment' => $fixture::assessment()]);
    $responses = $worker->feature === 'analyse' ? [$analysis]
        : [...($worker->baseline ? [] : [$analysis]), $fixture::response($fixture::draft()), $fixture::response($fixture::validation())];
    $sequence = Illuminate\Support\Facades\Http::sequence();
    foreach ($responses as $response) $sequence->push($response);
    Illuminate\Support\Facades\Http::fake(['api.openai.com/*' => $sequence]);
    $worker->handle();
    Illuminate\Support\Facades\DB::table('jobs')->where('id', $row->id)->delete();
    Illuminate\Support\Facades\Http::assertSentCount(count($responses));
    echo json_encode(['completed' => true, 'mocked_stages' => count($responses), 'paid_api_calls' => 0]);
    exit;
}
throw new RuntimeException('No matching fixture operation found.');
