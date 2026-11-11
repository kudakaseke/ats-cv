<?php

// Exports the exact assembled prompts. --live uses synthetic data only and
// calls the configured provider directly, without touching user credit wallets.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = base_path('docs/ai-quality-check');
if (! is_dir($directory)) mkdir($directory, 0755, true);
$prompt = app(App\Services\CvAiPrompt::class);
file_put_contents($directory.'/fix-my-cv-instructions.txt', $prompt->instructions(true));
file_put_contents($directory.'/scan-cv-instructions.txt', $prompt->instructions(false));
echo "Exported exact assembled scan and rewrite instructions.\n";
if (! in_array('--live', $argv, true)) exit(0);
if (config('services.cv_ai.provider') !== 'openai') throw new RuntimeException('Live audit expects the configured OpenAI provider.');
$original = <<<'CV'
Alex Audit
alex.audit@example.invalid
Software Developer
Professional Summary
I do websites and help with problems.
Technical Skills
PHP, Laravel, MySQL, Git
Experience
Position: Junior Web Developer
Company: Example Studio
Period: January 2023 - December 2025
Location: Harare
- I made internal web pages in PHP and Laravel for staff to record support requests.
- I fixed bugs reported by staff and wrote notes explaining the fixes.
- I used MySQL to store support requests and Git to track code changes.
- I talked to staff about what they needed and trained two colleagues to use the application.
Education
Degree: Diploma in Information Technology
School: Example Technical College
Period: 2022
Core Strengths
Good communication skills, Teamwork, Problem solving
CV;
$job = <<<'JOB'
Software Developer
Required skills:
PHP, Laravel, MySQL, Git
Required competencies:
Problem solving
Written communication
Teamwork
Responsibilities:
Maintain internal web applications, investigate reported issues, document changes and work with users to understand requirements.
Preferred skills:
Docker
JOB;
file_put_contents($directory.'/synthetic-input.json', json_encode(['note'=>'Synthetic audit data, not a real applicant.','job_description'=>$job,'original_resume'=>$original,'confirmed_keywords'=>[]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$phase = 'scan';
Illuminate\Support\Facades\Http::globalRequestMiddleware(function ($request) use ($directory, &$phase) {
    if (str_starts_with((string) $request->getUri(), 'https://api.openai.com/')) {
        // Request body only. Authorization headers and API keys are never saved.
        file_put_contents($directory.'/'.$phase.'-request.json', json_encode(json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return $request;
});
$rewriter = app(App\Services\CvAiRewriter::class);
$reuseScan = in_array('--reuse-scan', $argv, true) && is_file($directory.'/synthetic-scan.json');
$scan = $reuseScan ? json_decode(file_get_contents($directory.'/synthetic-scan.json'), true, 512, JSON_THROW_ON_ERROR) : $rewriter->analyse($job, $original);
$scanUsage = $reuseScan ? ['reused_saved_synthetic_baseline' => true] : $rewriter->lastUsage;
file_put_contents($directory.'/synthetic-scan.json', json_encode($scan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Live scan completed: '.json_encode($scanUsage)."\n";
$phase = 'rewrite';
$rewrite = $rewriter->rewrite($job, $original, [], $scan['assessment'] ?? null);
file_put_contents($directory.'/synthetic-rewrite.json', json_encode($rewrite, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$cv = $rewrite['structured_resume'];
$checks = [
    'summary_rewritten' => trim($cv['summary']) !== 'I do websites and help with problems.',
    'experience_rewritten' => ($cv['experience'][0]['responsibilities'][0] ?? '') !== 'I made internal web pages in PHP and Laravel for staff to record support requests.',
    'employer_preserved' => ($cv['experience'][0]['company'] ?? '') === 'Example Studio',
    'job_title_preserved' => ($cv['experience'][0]['title'] ?? '') === 'Junior Web Developer',
    'qualification_preserved' => ($cv['education'][0]['degree'] ?? '') === 'Diploma in Information Technology',
    'unconfirmed_docker_not_added' => ! preg_match('/\bdocker\b/i', $rewrite['rewritten_resume']),
    'changes_explained' => count($rewrite['changes']) > 0,
    'technical_skills_grouped' => count(array_filter($cv['skills'], fn ($row) => str_contains($row, ':'))) > 0,
    'no_inferred_skill_durations' => ! preg_match('/\d+(?:\.\d+)?\s*years?/i', implode(' ', $cv['skills'])),
    'responsibility_scope_not_upgraded' => ! preg_match('/(?:design(?:ed)?|manag(?:ed|ement)).{0,50}(?:database|data storage)|database design|code collaboration|collaborative code/i', implode(' ', $cv['experience'][0]['responsibilities'] ?? [])),
    'no_duplicate_skill_variants' => ! preg_match('/PHP.*?, PHP|MySQL.*?, MySQL|Laravel.*?, Laravel|Git.*?, Git/i', implode(' ', $cv['skills'])),
    'communication_separate' => ! preg_match('/communication/i', implode(' ', $cv['skills'])),
];
$report = ['model'=>config('services.openai.model'),'scan_usage'=>$scanUsage,'rewrite_usage'=>$rewriter->lastUsage,'checks'=>$checks,'passed'=>! in_array(false, $checks, true)];
file_put_contents($directory.'/live-quality-report.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report, JSON_PRETTY_PRINT)."\n";
if (! $report['passed']) exit(1);
