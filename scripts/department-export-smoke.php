<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Synthetic content only; never publish the user's reference CV or certificates.
$data = [
    'template' => 'department-it', 'name' => 'Print Verification', 'role' => 'IT Professional',
    'email' => 'test@example.com', 'phone' => '0123456789', 'location' => 'Cape Town',
    'summary' => 'Experienced professional providing reliable technical support and clear documentation.',
    'skills' => 'Support, Networking, Documentation, Security, Windows, Linux, Reporting, Communication',
    'experience' => array_map(fn ($i) => [
        'title' => 'Technical Specialist '.$i, 'company' => 'Example Organisation',
        'period' => 'Jan 2020 - Present', 'location' => 'Cape Town',
        'description' => implode("\n", array_fill(0, 6, 'Resolved support requests and documented the outcome accurately for colleagues and users.')),
    ], range(1, 5)),
    'education' => [['degree' => 'Diploma in IT', 'school' => 'Example College', 'period' => '2017 - 2019']],
    'references' => 'FINAL EXPORT CONTENT MARKER',
];
foreach (['pdf', 'word'] as $format) {
    $file = app(App\Services\CvDocumentExporter::class)->create($data, $format);
    $destination = storage_path('app/department-export-check.'.($format === 'pdf' ? 'pdf' : 'docx'));
    rename($file, $destination);
    echo $format.': '.filesize($destination)." bytes\n";
}
