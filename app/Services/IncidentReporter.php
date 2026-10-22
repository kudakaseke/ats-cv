<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function report(Throwable $exception): void
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
            'provider_http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
        ];
        try {
            Log::channel('incidents')->error('Application failure', $context);
        } catch (Throwable) {
            // Logging failure must not replace the original error or expose secrets.
            error_log('Application incident '.$context['reference'].' ('.$exception::class.')');
        }
    }

    private function relative(string $file): string
    {
        $root = str_replace('\\', '/', base_path()).'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
    }
}
