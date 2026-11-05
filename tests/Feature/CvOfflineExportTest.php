<?php

namespace Tests\Feature;

use App\Services\CvDocumentExporter;
use App\Services\RemoteDocumentService;
use Illuminate\Support\Str;
use Tests\TestCase;

class CvOfflineExportTest extends TestCase
{
    public function test_export_embeds_shared_javascript_dependencies_without_network_access(): void
    {
        $publicPath = public_path();
        $fixture = storage_path('framework/testing/export-assets-'.Str::uuid());
        mkdir($fixture.'/build', 0700, true);
        file_put_contents($fixture.'/build/manifest.json', json_encode([
            'resources/js/app.js' => ['file' => 'app.js', 'imports' => ['shared.js']],
            'shared.js' => ['file' => 'shared.js', 'imports' => ['nested.js']],
            'nested.js' => ['file' => 'nested.js'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($fixture.'/build/app.js', 'import { shared } from "./shared.js"; window.exportReady = shared;');
        file_put_contents($fixture.'/build/shared.js', 'import { value } from "./nested.js"; export const shared = value;');
        file_put_contents($fixture.'/build/nested.js', 'export const value = "offline-ready";');
        $output = null;
        try {
            $this->app->usePublicPath($fixture);
            config(['cv_exports.driver' => 'remote']);
            $this->mock(RemoteDocumentService::class)->shouldReceive('render')->once()
                ->withArgs(function (string $html, string $format, string $path): bool {
                    $this->assertSame('pdf', $format);
                    $this->assertStringNotContainsString('from "./shared.js"', $html);
                    $this->assertStringContainsString('data:text', $html);
                    $this->assertStringContainsString("'unsafe-eval' data:", $html);
                    $this->assertStringContainsString("default-src 'none'", $html);
                    preg_match('/base64,([A-Za-z0-9+\\/]+)/', str_replace('\\/', '/', $html), $matches);
                    $shared = base64_decode($matches[1], true);
                    $this->assertStringNotContainsString('"./nested.js"', $shared);
                    $this->assertStringContainsString(base64_encode('export const value = "offline-ready";'), $shared);
                    file_put_contents($path, 'PDF fixture');

                    return true;
                });
            $output = app(CvDocumentExporter::class)->create(['name' => 'Offline export', 'template' => 'essential'], 'pdf');
            $this->assertFileExists($output);
        } finally {
            $this->app->usePublicPath($publicPath);
            if ($output && is_file($output)) {
                unlink($output);
            }
            foreach (['manifest.json', 'app.js', 'shared.js', 'nested.js'] as $file) {
                unlink($fixture.'/build/'.$file);
            }
            rmdir($fixture.'/build');
            rmdir($fixture);
        }
    }
}
