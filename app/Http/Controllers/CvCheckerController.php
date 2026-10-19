<?php

namespace App\Http\Controllers;

use App\Services\CvAiRewriter;
use App\Services\CvKeywordMatcher;
use App\Services\CvPdfExtractor;
use App\Services\CvStructuredResume;
use App\Services\CvTextParser;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;

class CvCheckerController extends Controller
{
    public function scan(Request $request, CvKeywordMatcher $matcher)
    {
        $data = $request->validate(['job_description' => ['required', 'string', 'max:20000'], 'resume_text' => ['required', 'string', 'max:20000']]);
        $result = $matcher->compare($data['job_description'], $data['resume_text']);
        if ($result['total'] === 0) {
            throw ValidationException::withMessages(['job_description' => 'Add the job requirements or skills so we can compare keywords.']);
        }

        $analysis = DB::transaction(function () use ($request, $data, $result) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $remaining = app(PlanService::class)->remainingAnalyses($user);
            if ($remaining !== null && $remaining < 1) {
                throw ValidationException::withMessages(['resume_text' => 'You have used your monthly scan allowance. Your existing results remain available. Check your subscription or try again next month.']);
            }

            return $user->analyses()->create(['job_description' => $data['job_description'], 'status' => 'completed',
                'score' => (int) round($result['similarity_percent']), 'results' => $result]);
        });

        return response()->json([...$result, 'analysis_id' => $analysis->id]);
    }

    public function rewrite(Request $request, CvAiRewriter $rewriter, CvKeywordMatcher $matcher)
    {
        $data = $request->validate([
            'job_description' => ['required', 'string', 'max:20000'],
            'resume_text' => ['required', 'string', 'max:20000'],
            'confirmed_keywords' => ['present', 'array', 'max:200'],
            'confirmed_keywords.*' => ['required', 'string', 'max:200', 'distinct'],
            'ai_consent' => ['accepted'],
        ]);
        $before = $matcher->compare($data['job_description'], $data['resume_text']);
        $allowed = array_values($matcher->tokens($data['job_description']));
        foreach ($data['confirmed_keywords'] as $keyword) {
            if (! in_array($keyword, $allowed, true)) {
                throw ValidationException::withMessages(['confirmed_keywords' => 'Choose keywords from this job description.']);
            }
        }
        try {
            $draft = $rewriter->rewrite($data['job_description'], $data['resume_text'], $data['confirmed_keywords']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'AI rewriting is unavailable right now. Your CV is unchanged. Please try again.'], 503);
        }

        $request->session()->put('cv_checker.generated', ['text' => $draft['rewritten_resume'], 'structured_resume' => $draft['structured_resume']]);

        return response()->json([
            ...$draft,
            'before' => $before,
            'after' => $matcher->compare($data['job_description'], $draft['rewritten_resume']),
        ]);
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
        $content['template'] = 'ats-professional';
        $content['imported_text'] = $data['resume_text'];
        $draft = ['step' => 4, 'data' => $content];
        if ($request->boolean('generated')) {
            $source = $request->validate([
                'original_resume_text' => ['required', 'string', 'max:20000'], 'job_description' => ['required', 'string', 'max:20000'],
                'ai_changes' => ['sometimes', 'array', 'max:30'], 'ai_changes.*' => ['required', 'string', 'max:1000'],
            ]);
            $matcher = app(CvKeywordMatcher::class);
            $before = $matcher->compare($source['job_description'], $source['original_resume_text']);
            $after = $matcher->compare($source['job_description'], $data['resume_text']);
            $draft['review_ready'] = true;
            $draft['checker_result'] = [
                'method' => $after['method'],
                'before' => $before['similarity_percent'], 'after' => $after['similarity_percent'],
                'keyword_before' => $before['keyword_score'], 'keyword_after' => $after['keyword_score'],
                'added_keywords' => array_values(array_diff($after['matched'], $before['matched'])),
                'remaining_keywords' => $after['missing'], 'suggestions' => $after['suggestions'],
                'changes' => $source['ai_changes'] ?? [],
                'original_text' => $source['original_resume_text'], 'revised_text' => $data['resume_text'],
            ];
        }
        $request->session()->put('cv_builder', $draft);

        return redirect()->route('builder.start');
    }
}
