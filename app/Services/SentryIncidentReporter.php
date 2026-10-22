<?php

namespace App\Services;

use Sentry\Event;
use Sentry\EventId;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\State\HubInterface;
use Throwable;

class SentryIncidentReporter
{
    public function report(array $context): ?EventId
    {
        if (! config('sentry.dsn')) {
            return null;
        }
        try {
            $event = Event::createEvent();
            $event->setExtra(['ats_incident' => $context]);

            return app(HubInterface::class)->captureEvent($event);
        } catch (Throwable) {
            // The private incident log remains available if external reporting fails.
            return null;
        }
    }

    public static function integrations(array $integrations): array
    {
        // No request, exception, log or user integrations may enrich the payload.
        return [];
    }

    public static function beforeSend(Event $event): ?Event
    {
        $data = $event->getExtra()['ats_incident'] ?? null;
        if (! is_array($data) || ! preg_match('/^[a-f0-9-]{36}$/i', $data['reference'] ?? '')
            || ! preg_match('/^[a-f0-9]{64}$/', $data['fingerprint'] ?? '')) {
            return null; // Reject raw/third-party capture paths rather than forwarding their content.
        }
        $safe = Event::createEvent($event->getId());
        $safe->setTimestamp($event->getTimestamp());
        $safe->setLevel(Severity::error());
        $safe->setEnvironment(config('sentry.environment'));
        $safe->setRelease(config('sentry.release'));
        $safe->setMessage('Application failure');
        $safe->setTag('error_reference', $data['reference']);
        $type = self::codeName($data['type'] ?? 'ApplicationException');
        $safe->setTag('exception_type', $type);
        if (is_string($data['route'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,150}$/', $data['route'])) {
            $safe->setTag('route', $data['route']);
        }
        $safe->setFingerprint([$data['fingerprint']]);
        $frames = [];
        $locations = array_merge([['file' => $data['file'] ?? '', 'line' => $data['line'] ?? 0, 'call' => '']], array_slice($data['trace'] ?? [], 0, 20));
        foreach (array_reverse($locations) as $location) {
            $file = $location['file'] ?? '';
            if (! is_string($file) || ! preg_match('/^[A-Za-z0-9_\/.\-]{1,240}\.php$/', $file) || str_contains($file, '..') || str_starts_with($file, '/')) {
                $file = 'unknown.php';
            }
            $frames[] = new Frame(self::codeName($location['call'] ?? ''), $file, max(0, (int) ($location['line'] ?? 0)), null, null, [], ! str_starts_with($file, 'vendor/'));
        }
        $exception = new ExceptionDataBag(new \RuntimeException('Application failure'), new Stacktrace($frames));
        $exception->setType($type);
        $safe->setExceptions([$exception]);
        if (is_int($data['provider_http_status'] ?? null) && $data['provider_http_status'] >= 100 && $data['provider_http_status'] <= 599) {
            $safe->setTag('provider_http_status', (string) $data['provider_http_status']);
        }

        return $safe;
    }

    private static function codeName(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_\\\\:>\-]{1,200}$/', $value) ? $value : 'ApplicationException';
    }
}
