<?php

use App\Http\Controllers\CvCheckerController;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\CvAiRewriter;
use App\Services\CvKeywordMatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array('--live', $argv, true) || ! $app->environment('local')) {
    throw new RuntimeException('This local-only script requires --live and makes one billable OpenAI request.');
}
$user = User::create(['name' => 'AI Format Check', 'email' => 'browser-smoke-'.bin2hex(random_bytes(8)).'@example.invalid', 'password' => Str::random(40)]);
try {
    Auth::login($user);
    $resume = "Alex Example\napplicant@example.invalid\nIT Support Professional\nProfessional Summary\nIT support professional providing Windows and networking support.\nProfessional Experience\nIT Support Assistant | Example Organisation | Jan 2022 - Present\nResolved Windows and network issues, documented solutions and collaborated with colleagues.\nEducation\nDiploma in IT | Example College | 2021\nTechnical Skills\nWindows, Networking, Troubleshooting, Documentation\nCore Strengths\nProblem Solving, Communication, Teamwork, Attention to Detail\nKey Competencies\nAdaptability, Integrity, Initiative, Organisation\nReferences\nJane Example | IT Manager | 0121112222 | jane@example.invalid\nSam Example | Lecturer | 0123334444 | sam@example.invalid";
    $request = Request::create('/checker/rewrite', 'POST', [
        'job_description' => 'IT Support Professional: Windows support, networking, troubleshooting, documentation, communication, teamwork, problem solving, attention to detail, adaptability, integrity, initiative and organisation.',
        'resume_text' => $resume, 'confirmed_keywords' => [], 'operation_id' => (string) Str::uuid(),
    ]);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $response = app(CvCheckerController::class)->rewrite($request, app(CvAiRewriter::class), app(CvKeywordMatcher::class), app(AiCreditService::class));
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Live rewrite failed; application credits were refunded.');
    }
    $body = $response->getData(true);
    $draft = $body['structured_resume'];
    if ($draft['competencies'] !== [] || count($draft['core_strengths']) < 4 || $draft['email'] !== 'applicant@example.invalid') {
        throw new RuntimeException('The combined strengths or applicant identity contract failed.');
    }
    if (count(explode("\n", $draft['references'])) !== 2 || ! str_contains($draft['references'], 'jane@example.invalid') || ! str_contains($draft['references'], 'sam@example.invalid')) {
        throw new RuntimeException('The two original referees were not preserved on separate lines.');
    }
    file_put_contents(storage_path('app/ai-reference-live-draft.json'), json_encode($draft, JSON_PRETTY_PRINT));
    $usage = DB::table('ai_usage_logs')->where('user_id', $user->id)->first(['model', 'input_tokens', 'output_tokens', 'credits_charged', 'status']);
    echo json_encode(['success' => true, 'strengths' => count($draft['core_strengths']), 'separate_competencies' => 0, 'referees_preserved' => 2, 'credits_remaining' => $body['credits_remaining'], 'usage' => $usage], JSON_PRETTY_PRINT)."\n";
} finally {
    Auth::logout();
    $user->delete();
}
