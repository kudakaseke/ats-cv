<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RemoteDocumentService
{
    public function extract(string $path): array
    {
        if (! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Choose a PDF up to 5 MB.']);
        }
        $data = json_decode($this->request('/v1/extract', ['pdf' => base64_encode(file_get_contents($path))]), true, 16, JSON_THROW_ON_ERROR);
        if (! is_string($data['text'] ?? null) || trim($data['text']) === '' || mb_strlen($data['text']) > 20000
            || ! is_int($data['pages'] ?? null) || $data['pages'] < 1 || $data['pages'] > 100) {
            throw new \RuntimeException('Invalid document service extraction response.');
        }

        return $data;
    }

    public function render(string $html, string $format, string $output): void
    {
        $bytes = $this->request('/v1/render', ['html' => $html, 'format' => $format]);
        if (! str_starts_with($bytes, $format === 'pdf' ? '%PDF-' : "PK\x03\x04")) {
            throw new \RuntimeException('Invalid document service export response.');
        }
        if (file_put_contents($output, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('The remote export could not be written.');
        }
    }

    public function healthy(): bool
    {
        $data = json_decode($this->request('/v1/health', ['probe' => true]), true, 16, JSON_THROW_ON_ERROR);

        return ($data['ready'] ?? false) === true && ($data['protocol'] ?? null) === 1
            && empty(array_diff(['extract', 'pdf', 'word'], $data['capabilities'] ?? []));
    }

    private function configuration(): array
    {
        $url = rtrim((string) config('cv_exports.remote_url'), '/');
        $key = (string) config('cv_exports.remote_key');
        $parts = parse_url($url);
        $localHttp = ! app()->isProduction() && config('cv_exports.allow_local_http')
            && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        if (! is_array($parts) || ! isset($parts['host']) || ! preg_match('/^[a-f0-9]{64}$/i', $key)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (! $localHttp && (($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['port'] ?? 443, [443], true)))) {
            throw new \RuntimeException('Configure a secure document service URL and a 32-byte hexadecimal service key.');
        }

        return [$url, $key];
    }

    private function request(string $path, array $payload): string
    {
        [$url, $key] = $this->configuration();
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($body) > 8 * 1024 * 1024) {
            throw new \RuntimeException('Document service request is too large.');
        }
        $time = (string) time();
        $nonce = (string) Str::uuid();
        $hash = hash('sha256', $body);
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$time}\n{$nonce}\n{$hash}", $key);
        $response = Http::connectTimeout(10)->timeout(75)->withoutRedirecting()
            ->withOptions(['stream' => true, 'read_timeout' => 75])
            ->withHeaders(['X-Document-Timestamp' => $time, 'X-Document-Nonce' => $nonce,
                'X-Document-Content-SHA256' => $hash, 'X-Document-Signature' => $signature])
            ->withBody($body, 'application/json')->post($url.$path);
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';
        try {
            while (! $stream->eof()) {
                $bytes .= $stream->read(65536);
                if (strlen($bytes) > 20 * 1024 * 1024) {
                    throw new \RuntimeException('Document service response is too large.');
                }
            }
        } finally {
            $stream->close();
        }
        $expected = hash_hmac('sha256', "response\n{$nonce}\n".$response->status()."\n".hash('sha256', $bytes), $key);
        if (! hash_equals($expected, (string) $response->header('X-Document-Response-Signature'))) {
            throw new \RuntimeException('Document service response authentication failed.');
        }
        if ($response->status() === 422 && $path === '/v1/extract') {
            throw ValidationException::withMessages(['file' => 'This PDF could not be read. Use a text-based PDF up to 100 pages and 20,000 characters, without a password.']);
        }
        if (! $response->successful()) {
            throw new \RuntimeException('The document service is temporarily unavailable.');
        }

        return $bytes;
    }
}
