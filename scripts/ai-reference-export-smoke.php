<?php

use App\Services\CvDocumentExporter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$data = [
    'template' => 'ats-professional', 'name' => 'Alex Example', 'role' => 'IT Support Professional',
    'email' => 'applicant@example.invalid', 'phone' => '0123456789', 'location' => 'Cape Town',
    'summary' => 'IT support professional with experience resolving technical issues and collaborating with colleagues.',
    'skills' => 'Windows, Networking, Troubleshooting, Documentation, Excel, Reporting, Customer Support, Hardware, Software, Testing',
    'core_strengths' => 'Problem Solving, Communication, Teamwork, Attention to Detail, Adaptability, Integrity, Initiative, Organisation, Reliability, Planning',
    'references' => "Jane Example | IT Manager | 0121112222 | jane@example.invalid\nSam Example | Lecturer | 0123334444 | sam@example.invalid",
    'experience' => [['title' => 'IT Support Assistant', 'company' => 'Example Organisation', 'period' => 'Jan 2022 - Present', 'location' => '', 'description' => "Resolved support requests.\nDocumented solutions for colleagues."]],
    'education' => [['degree' => 'Diploma in IT', 'school' => 'Example College', 'period' => '2020 - 2021', 'location' => '', 'grade' => 'Distinction']],
    'achievements' => [['title' => 'Service Award', 'description' => 'Recognised for reliable customer support.']],
    'custom_sections' => [['title' => 'Projects', 'content' => "Reporting Tool\nAutomated the monthly reporting process.\n\nBudget Tracker\nTracked spending against approved budgets."]],
    'courses' => [['title' => 'Example Training Certificate', 'institution' => '', 'period' => '2024']],
];
foreach (['pdf', 'word'] as $format) {
    $file = app(CvDocumentExporter::class)->create($data, $format);
    $destination = storage_path('app/ai-reference-example.'.($format === 'pdf' ? 'pdf' : 'docx'));
    rename($file, $destination);
    echo $format.': '.filesize($destination)." bytes\n";
}
