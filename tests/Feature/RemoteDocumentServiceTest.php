<?php

namespace Tests\Feature;

use App\Services\CvDocumentExporter;
use App\Services\CvPdfExtractor;
use App\Services\RemoteDocumentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class RemoteDocumentServiceTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = bin2hex(random_bytes(32));
        config(['cv_exports.driver' => 'remote', 'cv_exports.remote_url' => 'https://documents.example.invalid', 'cv_exports.remote_key' => $this->key]);
    }

    private function fake(string $bytes, int $status = 200, bool $validSignature = true): void
    {
        Http::fake(function ($request) use ($bytes, $status, $validSignature) {
            $body = $request->body();
            $path = parse_url($request->url(), PHP_URL_PATH);
            $time = $request->header('X-Document-Timestamp')[0];
            $nonce = $request->header('X-Document-Nonce')[0];
            $hash = hash('sha256', $body);
            $this->assertSame($hash, $request->header('X-Document-Content-SHA256')[0]);
            $this->assertSame(hash_hmac('sha256', "POST\n{$path}\n{$time}\n{$nonce}\n{$hash}", $this->key), $request->header('X-Document-Signature')[0]);

            return Http::response($bytes, $status, ['X-Document-Response-Signature' => $validSignature
                ? hash_hmac('sha256', "response\n{$nonce}\n{$status}\n".hash('sha256', $bytes), $this->key) : str_repeat('0', 64)]);
        });
    }

    public function test_remote_pdf_extraction_works_without_a_local_python_binary(): void
    {
        config(['cv_exports.python' => '/missing/python']);
        $this->fake(json_encode(['text' => 'Synthetic Candidate', 'pages' => 1]));
        $path = tempnam(sys_get_temp_dir(), 'remote-pdf-');
        try {
            file_put_contents($path, '%PDF-synthetic');
            $this->assertSame('Synthetic Candidate', app(CvPdfExtractor::class)->extract($path)['text']);
        } finally {
            unlink($path);
        }
    }

    public function test_remote_health_checks_all_required_capabilities(): void
    {
        $this->fake(json_encode(['ready' => true, 'protocol' => 1, 'capabilities' => ['extract', 'pdf', 'word']]));
        $this->assertTrue(app(RemoteDocumentService::class)->healthy());
    }

    public function test_tampered_remote_outputs_are_rejected(): void
    {
        $this->fake('{"ready":true}', 200, false);
        $this->expectException(\RuntimeException::class);
        app(RemoteDocumentService::class)->healthy();
    }

    public function test_insecure_remote_configuration_is_rejected_before_network_access(): void
    {
        Http::preventStrayRequests();
        foreach (['http://documents.example.invalid', 'https://user:password@documents.example.invalid', 'https://documents.example.invalid/other', 'https://documents.example.invalid?token=secret'] as $url) {
            config(['cv_exports.remote_url' => $url]);
            try {
                app(RemoteDocumentService::class)->healthy();
                $this->fail('Unsafe configuration accepted.');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_authenticated_invalid_pdf_returns_a_validation_error(): void
    {
        $this->fake('{"error":"unreadable"}', 422);
        $path = tempnam(sys_get_temp_dir(), 'remote-pdf-');
        try {
            file_put_contents($path, '%PDF-synthetic');
            $this->expectException(ValidationException::class);
            app(RemoteDocumentService::class)->extract($path);
        } finally {
            unlink($path);
        }
    }

    public function test_production_rejects_loopback_http_even_with_the_local_exception_enabled(): void
    {
        $this->app['env'] = 'production';
        config(['cv_exports.remote_url' => 'http://127.0.0.1:3100', 'cv_exports.allow_local_http' => true]);
        Http::preventStrayRequests();
        $this->expectException(\RuntimeException::class);
        app(RemoteDocumentService::class)->healthy();
    }

    public function test_remote_outage_is_not_reported_as_an_invalid_cv(): void
    {
        $this->fake('{"error":"unavailable"}', 503);
        $path = tempnam(sys_get_temp_dir(), 'remote-pdf-');
        try {
            file_put_contents($path, '%PDF-synthetic');
            app(CvPdfExtractor::class)->extract($path);
            $this->fail('Service outage was accepted.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('exportFormats')]
    public function test_remote_export_uses_existing_renderer_without_local_node(string $format, string $bytes): void
    {
        config(['cv_exports.node' => '/missing/node']);
        $this->fake($bytes);
        $path = app(CvDocumentExporter::class)->create(['name' => 'Synthetic Candidate', 'template' => 'essential'], $format);
        try {
            $this->assertSame($bytes, file_get_contents($path));
            Http::assertSent(fn ($request) => str_contains($request->body(), 'reviewPreview') && str_contains($request->body(), 'Synthetic Candidate'));
        } finally {
            unlink($path);
        }
    }

    public static function exportFormats(): array
    {
        return [['pdf', '%PDF-synthetic-output'], ['word', "PK\x03\x04synthetic-output"]];
    }
}
