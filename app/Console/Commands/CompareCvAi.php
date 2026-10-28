<?php

namespace App\Console\Commands;

use App\Services\CvAiRewriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CompareCvAi extends Command
{
    protected $signature = 'cv:compare-ai {job : UTF-8 job description file} {resume : UTF-8 CV file} {--live : Send billable requests to both providers} {--rewrite : Compare rewriting as well as analysis}';

    protected $description = 'Compare GPT and Kimi using identical inputs; save private results and token usage';

    public function handle(): int
    {
        foreach (['job', 'resume'] as $argument) {
            $path = $this->argument($argument);
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 200000) {
                $this->error('Inputs must be readable text files under 200 KB.');
                return self::FAILURE;
            }
            $inputs[$argument] = file_get_contents($path);
            if (! trim($inputs[$argument]) || ! mb_check_encoding($inputs[$argument], 'UTF-8')) {
                $this->error('Inputs must contain UTF-8 text.');
                return self::FAILURE;
            }
        }
        foreach (['openai', 'kimi'] as $provider) {
            if (! config('services.'.$provider.'.key') || ! config('services.'.$provider.'.model')) {
                $this->error('Configure the '.$provider.' API key and model in .env first.');
                return self::FAILURE;
            }
        }
        if (! $this->option('live')) {
            $this->info('Ready. Add --live to send billable requests. Add --rewrite to compare CV rewriting too.');
            return self::SUCCESS;
        }

        $originalProvider = config('services.cv_ai.provider');
        $report = ['created_at' => now()->toIso8601String(), 'results' => []];
        $failed = false;
        try {
            foreach (['openai', 'kimi'] as $provider) {
                config(['services.cv_ai.provider' => $provider]);
                $service = new CvAiRewriter;
                $baseline = null;
                foreach ($this->option('rewrite') ? ['analyse', 'rewrite'] : ['analyse'] as $operation) {
                    $started = microtime(true);
                    try {
                        $result = $operation === 'analyse'
                            ? $service->analyse($inputs['job'], $inputs['resume'])
                            : $service->rewrite($inputs['job'], $inputs['resume'], [], $baseline);
                        if ($operation === 'analyse') {
                            $baseline = $result['assessment'] ?? null;
                        }
                        $report['results'][$provider][$operation]['result'] = $result;
                        $this->info($provider.' '.$operation.': passed validation');
                    } catch (\Throwable $exception) {
                        // Provider responses and validation messages may contain private CV text.
                        $report['results'][$provider][$operation]['error_type'] = get_class($exception);
                        if ($exception instanceof \App\Exceptions\AiResponseException) {
                            $report['results'][$provider][$operation]['http_status'] = $exception->providerStatus;
                            $report['results'][$provider][$operation]['failure_reason'] = $exception->failureReason;
                        }
                        $this->error($provider.' '.$operation.': failed ('.class_basename($exception).')');
                        $failed = true;
                    }
                    $report['results'][$provider][$operation]['seconds'] = round(microtime(true) - $started, 3);
                    $report['results'][$provider][$operation]['usage'] = $service->lastUsage;
                }
            }
        } finally {
            config(['services.cv_ai.provider' => $originalProvider]);
        }
        $path = 'ai-comparisons/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            $this->error('Could not save the comparison report.');
            return self::FAILURE;
        }
        $this->info('Private report: '.Storage::disk('local')->path($path));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
