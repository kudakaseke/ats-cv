<?php

// Synthetic documents only. The launcher supplies an ephemeral localhost key.
use App\Services\CvDocumentExporter;
use App\Services\CvPdfExtractor;
use App\Services\RemoteDocumentService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$paths = [];
$failed = false;
try {
    config(['cv_exports.remote_url' => getenv('SMOKE_DOCUMENT_URL'), 'cv_exports.remote_key' => getenv('SMOKE_DOCUMENT_KEY'), 'cv_exports.allow_local_http' => true]);
    if ($app->isProduction()) {
        throw new RuntimeException('Local smoke test must not run in production.');
    }
    if (! app(RemoteDocumentService::class)->healthy()) {
        throw new RuntimeException('Document service health failed.');
    }
    $data = ['name' => 'Synthetic Candidate', 'template' => 'essential', 'email' => 'synthetic@example.invalid', 'summary' => 'Synthetic document service integration check.'];
    $texts = [];
    $wordXml = [];
    foreach (['local', 'remote'] as $driver) {
        config(['cv_exports.driver' => $driver]);
        foreach (['pdf', 'word'] as $format) {
            $paths[] = $output = app(CvDocumentExporter::class)->create($data, $format);
            if ($format === 'pdf') {
                $texts[$driver] = app(CvPdfExtractor::class)->extract($output);
            } else {
                $paths[] = $archivePath = $output.'.zip';
                copy($output, $archivePath);
                $zip = new PharData($archivePath, 0, null, Phar::ZIP);
                $wordXml[$driver] = $zip['word/document.xml']->getContent();
                unset($zip);
            }
        }
    }
    if ($texts['local'] !== $texts['remote']) {
        throw new RuntimeException('PDF extraction parity failed.');
    }
    if (! str_contains(strtolower($texts['remote']['text']), 'synthetic candidate')) {
        throw new RuntimeException('Synthetic name missing from PDF.');
    }
    if ($wordXml['local'] !== $wordXml['remote']) {
        throw new RuntimeException('Word layout XML parity failed.');
    }
    echo "PASS: authenticated health, local/remote PDF text and page parity, identical Word document XML.\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
} finally {
    foreach ($paths as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
exit($failed ? 1 : 0);
