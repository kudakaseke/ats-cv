<?php

namespace App\Http\Controllers;

use App\Services\AiCreditService;
use App\Services\CvAiRewriter;
use App\Services\CvCompatibilityChecker;
use App\Services\CvKeywordMatcher;
use App\Services\CvPdfExtractor;
use App\Services\CvResumeFormat;
use App\Services\CvReviewComparison;
use App\Services\CvStructuredResume;
use App\Services\CvTextParser;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CvCheckerController extends Controller
{
    public function detect(Request $request, CvCompatibilityChecker $checker)
    {
        $data = $request->validate(['resume_text' => ['required', 'string', 'max:20000']]);

        return response()->json($checker->check($data['resume_text']));
    }

    public function scan(Request $request, CvKeywordMatcher $matcher, AiCreditService $credits, CvAiRewriter $ai)
    {
        $request->validate(['operation_id' => ['sometimes', 'uuid']]);
        $data = $request->validate(['job_description' => ['required', 'string', 'max:20000'], 'resume_text' => ['required', 'string', 'max:20000']]);
        $result = $matcher->compare($data['job_description'], $data['resume_text']);
        if ($result['total'] === 0) {
            throw ValidationException::withMessages(['job_description' => 'Add the job requirements or skills so we can compare keywords.']);
        }

        $operation = $credits->reserve($request->user(), 'analyse', $request->input('operation_id', (string) Str::uuid()), $data);
        if ($operation->cached) {
            return response()->json([...$operation->result, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
        }
        try {
            $result['ai_analysis'] = $ai->analyse($data['job_description'], $data['resume_text']);
            $payload = DB::transaction(function () use ($request, $data, $result, $credits, $operation, $ai) {
                $analysis = $request->user()->analyses()->create(['job_description' => $data['job_description'], 'status' => 'completed',
                    'score' => (int) round($result['similarity_percent']), 'results' => $result]);
                $payload = [...$result, 'analysis_id' => $analysis->id, 'credits_used' => config('ai_credits.costs.analyse')];
                $credits->finish($request->user(), $operation->id, $payload, $ai->lastUsage);

                return $payload;
            });
        } catch (\Throwable $exception) {
            report($exception);
            $credits->finish($request->user(), $operation->id, null, $ai->lastUsage);

            return response()->json(['message' => 'Job-to-CV ATS Detector could not be completed. Your credits were refunded. Please try again.', 'credits_remaining' => $credits->wallet($request->user())['credits']], 503);
        }

        return response()->json([...$payload, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
    }

    public function rewrite(Request $request, CvAiRewriter $rewriter, CvKeywordMatcher $matcher, AiCreditService $credits)
    {
        $data = $request->validate([
            'job_description' => ['required', 'string', 'max:20000'],
            'resume_text' => ['required', 'string', 'max:20000'],
            'confirmed_keywords' => ['present', 'array', 'max:200'],
            'confirmed_keywords.*' => ['required', 'string', 'max:200', 'distinct'],
        ]);
        $request->validate(['operation_id' => ['sometimes', 'uuid']]);
        $before = $matcher->compare($data['job_description'], $data['resume_text']);
        $allowed = $before['confirmable_skills'] ?? array_values($matcher->tokens($data['job_description']));
        foreach ($data['confirmed_keywords'] as $keyword) {
            if (! in_array($keyword, $allowed, true)) {
                throw ValidationException::withMessages(['confirmed_keywords' => 'Choose keywords from this job description.']);
            }
        }
        $operation = $credits->reserve($request->user(), 'revamp', $request->input('operation_id', (string) Str::uuid()), $data);
        if ($operation->cached) {
            $payload = $operation->result;
            $request->session()->put('cv_checker.generated', ['text' => $payload['rewritten_resume'], 'structured_resume' => $payload['structured_resume']]);

            return response()->json([...$payload, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
        }
        try {
            $draft = $rewriter->rewrite($data['job_description'], $data['resume_text'], $data['confirmed_keywords']);
            $payload = [...$draft, 'before' => $before, 'after' => $matcher->compare($data['job_description'], $draft['rewritten_resume']),
                'credits_used' => config('ai_credits.costs.revamp')];
            $credits->finish($request->user(), $operation->id, $payload, $rewriter->lastUsage);
        } catch (\Throwable $exception) {
            report($exception);
            $credits->finish($request->user(), $operation->id, null, $rewriter->lastUsage);

            return response()->json(['message' => 'AI rewriting is unavailable right now. Your CV is unchanged and your credits were refunded. Please try again.', 'credits_remaining' => $credits->wallet($request->user())['credits']], 503);
        }

        $request->session()->put('cv_checker.generated', ['text' => $draft['rewritten_resume'], 'structured_resume' => $draft['structured_resume']]);

        return response()->json([...$payload, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
    }

    public function credits(Request $request, AiCreditService $credits)
    {
        return response()->json($credits->wallet($request->user()));
    }

    public function upload(Request $request, CvBuilderController $builder)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        if (strtolower($request->file('file')->getClientOriginalExtension()) === 'pdf') {
            $request->validate(['file' => ['mimetypes:application/pdf', 'extensions:pdf']]);

            return response()->json(app(CvPdfExtractor::class)->extract($request->file('file')->getRealPath()));
        }
        if (strtolower($request->file('file')?->getClientOriginalExtension() ?? '') !== 'txt') {
            return $builder->import($request);
        }
        $request->validate(['file' => ['required', 'file', 'extensions:txt', 'mimetypes:text/plain', 'max:5120']]);
        $text = file_get_contents($request->file('file')->getRealPath());
        if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '') {
            throw ValidationException::withMessages(['file' => 'Upload a UTF-8 text file containing your resume.']);
        }
        if (mb_strlen($text) > 20000) {
            throw ValidationException::withMessages(['file' => 'Your resume must contain no more than 20,000 characters.']);
        }

        return response()->json(['text' => trim($text)]);
    }

    public function edit(Request $request, CvTextParser $parser)
    {
        $data = $request->validate(['resume_text' => ['required', 'string', 'max:20000']]);
        $content = null;
        if ($request->boolean('generated') && $request->filled('structured_resume')) {
            $request->validate(['structured_resume' => ['string', 'json', 'max:80000']]);
            try {
                $structured = json_decode($request->input('structured_resume'), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw ValidationException::withMessages(['structured_resume' => 'The structured CV is too deeply nested or invalid. Please regenerate it or use the original CV.']);
            }
            if (is_array($structured)) {
                $mapper = app(CvStructuredResume::class);
                $content = $mapper->data($structured);
                $data['resume_text'] = $mapper->text($content);
            }
        }
        $cached = $request->session()->get('cv_checker.generated');
        if ($content === null && $request->boolean('generated') && is_array($cached) && hash_equals($cached['text'], $data['resume_text'])) {
            $content = app(CvStructuredResume::class)->data($cached['structured_resume']);
        }
        $content ??= $parser->parse($data['resume_text']);
        $content = CvResumeFormat::builder($content);
        $content['template'] = in_array('ats-professional', app(PlanService::class)->templateIds($request->user()), true) ? 'ats-professional' : 'essential';
        $content['imported_text'] = $data['resume_text'];
        $draft = ['step' => 4, 'data' => $content];
        if ($request->boolean('generated')) {
            $source = $request->validate([
                'original_resume_text' => ['required', 'string', 'max:20000'], 'job_description' => ['required', 'string', 'max:20000'],
                'ai_changes' => ['sometimes', 'array', 'max:30'], 'ai_changes.*' => ['required', 'string', 'max:1000'],
            ]);
            $draft['review_ready'] = true;
            $draft['checker_result'] = app(CvReviewComparison::class)->make($source['job_description'], $source['original_resume_text'], $data['resume_text'], $source['ai_changes'] ?? []);
        }
        $request->session()->put('cv_builder', $draft);

        return redirect()->route('builder.start');
    }
}
