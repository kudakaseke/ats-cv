<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sentry\EventId;
use Throwable;

class IncidentReporter
{
    public function reference(): string
    {
        $request = request();
        if (! $request->attributes->has('error_reference')) {
            $request->attributes->set('error_reference', (string) Str::uuid());
        }

        return $request->attributes->get('error_reference');
    }

    public function report(Throwable $exception): ?EventId
    {
        // Exceptions can embed SQL bindings, provider credentials or CV text.
        // Record code locations, never messages, arguments, URLs or request bodies.
        $trace = array_map(fn (array $frame) => [
            'file' => $this->relative($frame['file'] ?? ''),
            'line' => $frame['line'] ?? null,
            'call' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
        ], array_slice($exception->getTrace(), 0, 20));
        $context = [
            'reference' => app()->runningInConsole() && ! request()->route() ? (string) Str::uuid() : $this->reference(), 'type' => $exception::class,
            'file' => $this->relative($exception->getFile()), 'line' => $exception->getLine(),
            'route' => request()->route()?->getName(),
            'fingerprint' => hash('sha256', $exception::class.'|'.$exception->getFile().'|'.$exception->getLine()),
            'trace' => $trace,
            'provider_http_status' => $exception instanceof \App\Exceptions\AiResponseException ? $exception->providerStatus : ($exception instanceof RequestException ? $exception->response->status() : null),
            'ai_failure_reason' => $exception instanceof \App\Exceptions\AiResponseException ? $exception->failureReason : null,
            'transport' => $this->transport($exception),
            'ai_request' => $this->aiRequest(),
        ];
        try {
            Log::channel('incidents')->error('Application failure', $context);
        } catch (Throwable) {
            // Logging failure must not replace the original error or expose secrets.
            error_log('Application incident '.$context['reference'].' ('.$exception::class.')');
        }

        return app(SentryIncidentReporter::class)->report($context);
    }

    private function relative(string $file): string
    {
        $root = str_replace('\\', '/', base_path()).'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
    }

    private function transport(Throwable $exception): array
    {
        if (! $exception instanceof \Illuminate\Http\Client\ConnectionException) return [];
        // Numeric timings help separate DNS/TLS/connect failures from a slow
        // generation. Never retain URLs, headers, bodies or exception messages.
        for ($cause = $exception, $depth = 0; $cause && $depth < 5; $cause = $cause->getPrevious(), $depth++) {
            if (! $cause instanceof \GuzzleHttp\Exception\ConnectException) continue;
            $context = $cause->getHandlerContext();
            $safe = [];
            foreach (['errno', 'total_time', 'namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'size_upload', 'size_download'] as $field) {
                if (isset($context[$field]) && is_numeric($context[$field]) && is_finite((float) $context[$field]) && $context[$field] >= 0) {
                    $safe[$field] = $field === 'errno' ? (int) $context[$field] : round((float) $context[$field], 4);
                }
            }
            return $safe;
        }
        return [];
    }

    private function aiRequest(): array
    {
        $metrics = request()->attributes->get('ai_request_metrics', []);
        $safe = [];
        foreach (['duration_ms', 'instruction_characters', 'input_characters', 'max_output_tokens', 'http_status'] as $field) {
            if (isset($metrics[$field]) && is_int($metrics[$field]) && $metrics[$field] >= 0) $safe[$field] = $metrics[$field];
        }
        foreach (['model', 'action', 'request_id'] as $field) {
            if (is_string($metrics[$field] ?? null) && preg_match('/^[A-Za-z0-9._-]{1,150}$/', $metrics[$field])) $safe[$field] = $metrics[$field];
        }
        return $safe;
    }
}
