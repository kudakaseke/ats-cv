<?php

use App\Services\CvDocumentExporter;
use Illuminate\Contracts\Console\Kernel;
use Smalot\PdfParser\Parser;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing')) {
    throw new RuntimeException('Export audits are restricted to local/testing environments.');
}
$root = realpath(storage_path('app/private/cv-exports'));
$count = 0;
foreach (config('cv_templates') as $template) {
    foreach (['pdf', 'word'] as $format) {
        $path = null;
        try {
            $data = ['template' => $template['id'], 'name' => 'Export Audit', 'role' => 'Software Developer', 'email' => 'audit@example.invalid',
                'phone' => '+263 77 000 0000', 'location' => 'Harare, Zimbabwe', 'website' => '',
                'summary' => 'Builds reliable applications using PHP, SQL and JavaScript.', 'skills' => 'PHP, SQL, JavaScript',
                'core_strengths' => 'Adaptability', 'competencies' => 'Project planning', 'references' => 'Available on request',
                'experience' => [['title' => 'Developer', 'company' => 'Fictional Studio', 'period' => '2023 - Present', 'location' => 'Harare', 'description' => 'Built accessible websites.']],
                'education' => [['degree' => 'Computing Diploma', 'school' => 'Fictional College', 'period' => '2020 - 2022', 'location' => 'Harare']],
                'achievements' => [], 'courses' => [], 'languages' => [['name' => 'English', 'level' => 'Fluent']],
                'custom_sections' => [['title' => 'Volunteering', 'content' => 'EXPORTMARKER community support']]];
            $path = app(CvDocumentExporter::class)->create($data, $format);
            $root ??= realpath(storage_path('app/private/cv-exports'));
            if (realpath(dirname($path)) !== $root) {
                throw new RuntimeException('Unexpected output location.');
            }
            if ($format === 'pdf') {
                $pdf = (new Parser)->parseFile($path);
                if (count($pdf->getPages()) !== 1) {
                    throw new RuntimeException('Unexpected page count.');
                }
                $text = $pdf->getText();
                unset($pdf);
            } else {
                copy($path, $path.'.zip');
                $archive = new PharData($path.'.zip', 0, null, Phar::ZIP);
                $xml = $archive['word/document.xml']->getContent();
                $document = new DOMDocument;
                if (! $document->loadXML($xml, LIBXML_NONET)) {
                    throw new RuntimeException('Invalid Word document XML.');
                }
                $text = $document->textContent;
                unset($archive);
            }
            $normalised = preg_replace('/\s+/u', '', strtoupper($text));
            foreach (['EXPORTAUDIT', 'PHP', 'FICTIONALSTUDIO', 'EXPORTMARKER'] as $marker) {
                if (! str_contains($normalised, $marker)) {
                    throw new RuntimeException('Missing output content: '.$marker);
                }
            }
            $count++;
            echo 'PASS '.$template['id'].' '.$format.PHP_EOL;
        } finally {
            if ($path && realpath(dirname($path)) === $root) {
                foreach ([$path, $path.'.zip'] as $temporary) {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
        }
    }
}
echo 'Validated '.$count.' real PDF/Word exports.'.PHP_EOL;
