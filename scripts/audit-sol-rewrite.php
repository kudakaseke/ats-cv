<?php

// Explicit opt-in: one billable request with fictional documents. Never retries.
if (! in_array('--live', $argv, true)) {
    fwrite(STDERR, "Pass --live to make one billable synthetic CV rewrite.\n");
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('services.cv_ai.provider') !== 'openai' || config('services.openai.model') !== 'gpt-6.1-sol' || config('services.openai.validate_revamp')) {
    throw new RuntimeException('Expected the direct Sol configuration.');
}
$service = app(App\Services\CvAiRewriter::class);
$input = in_array('--representative', $argv, true)
    ? json_decode((new Symfony\Component\Process\Process(['node', base_path('scripts/sol-production-browser-audit.mjs'), '--input']))->mustRun()->getOutput(), true, 64, JSON_THROW_ON_ERROR)
    : ['synthetic_documents' => true, 'job' => 'PHP Developer. Required: PHP and a Diploma in Computing. AWS certification is desirable.',
        'resume' => "Alex Example\nPHP Developer\nSummary\nBuilds PHP applications.\nSkills\nPHP\nEducation\nDiploma in Computing"];
Illuminate\Support\Facades\Http::globalResponseMiddleware(function ($response) use ($input) {
    file_put_contents(storage_path('app/private/sol-synthetic-provider-replay.json'), json_encode([
        ...$input, 'response' => json_decode((string) $response->getBody(), true),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $response;
});
$started = microtime(true);
$report = ['success' => false, 'synthetic_documents' => true];
try {
    $result = $service->rewrite($input['job'], $input['resume'], []);
    $report += ['has_cv' => ! empty($result['rewritten_resume']), 'has_changes' => ! empty($result['ai_changes']),
        'has_remaining_warnings' => ! empty($result['remaining_warnings']),
        'validation_stage' => $result['post_revamp_validation']['stage'] ?? null];
    $report['success'] = true;
} catch (Throwable $exception) {
    $report['exception_type'] = $exception::class;
    // Do not print provider messages, keys, CVs, or request bodies.
    if ($exception instanceof App\Exceptions\AiResponseException) {
        $report['provider_http_status'] = $exception->providerStatus;
        $report['failure_reason'] = $exception->failureReason;
    }
    if ($exception instanceof Illuminate\Validation\ValidationException) {
        $report['invalid_fields'] = array_keys($exception->errors());
    }
    if ($exception instanceof RuntimeException && ! $exception instanceof Illuminate\Http\Client\ConnectionException) {
        $report['local_validation_message'] = $exception->getMessage();
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
$report['usage'] = $service->lastUsage;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
file_put_contents(storage_path('app/private/sol-direct-rewrite-audit.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
exit($report['success'] ? 0 : 1);
