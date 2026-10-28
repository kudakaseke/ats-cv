<?php

namespace App\Http\Controllers;

use App\Services\AiCreditService;
use App\Services\CvAiRewriter;
use App\Services\CvCompatibilityChecker;
use App\Services\CvDocumentIdentity;
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

        $background = $this->usesBackground($request);
        $operation = $request->attributes->get('ai_reserved_operation')
            ?? $credits->reserve($request->user(), 'analyse', $request->input('operation_id', (string) Str::uuid()), $data, $background);
        if ($operation->cached) {
            $request->session()->put('cv_checker.scan', ['signature' => CvDocumentIdentity::signature($data['job_description'], $data['resume_text']), 'assessment' => $operation->result['ai_analysis']['assessment'] ?? null]);
            $cached = [...$operation->result, ...$result];
            $cached['experience_check'] = app(\App\Services\CvExperienceMatcher::class)->reconcile($result['experience_check'], $cached['assessment'] ?? $cached['ai_analysis']['assessment'] ?? null);
            $cached = app(\App\Services\CvCompatibilityScore::class)->scan($cached);
            return response()->json([...$cached, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
        }
        if ($background) {
            return $this->queueOperation($request, $data, $operation, 'analyse', $credits);
        }
        try {
            $result['ai_analysis'] = $ai->analyse($data['job_description'], $data['resume_text']);
            if (isset($result['ai_analysis']['assessment'])) {
                $result['assessment'] = $result['ai_analysis']['assessment'];
                $result['experience_check'] = app(\App\Services\CvExperienceMatcher::class)->reconcile($result['experience_check'], $result['assessment']);
            }
            $result = app(\App\Services\CvCompatibilityScore::class)->scan($result);
            $payload = DB::transaction(function () use ($request, $data, $result, $credits, $operation, $ai) {
                $analysis = $request->user()->analyses()->create(['job_description' => $data['job_description'], 'status' => 'completed',
                    'score' => (int) round($result['keyword_score'] ?? $result['similarity_percent']), 'results' => $result]);
                $payload = [...$result, 'analysis_id' => $analysis->id, 'source_signature' => CvDocumentIdentity::signature($data['job_description'], $data['resume_text']), 'credits_used' => config('ai_credits.costs.analyse')];
                $credits->finish($request->user(), $operation->id, $payload, $ai->lastUsage);

                return $payload;
            });
        } catch (\Throwable $exception) {
            $credits->finish($request->user(), $operation->id, null, $ai->lastUsage);
            $this->rememberAiFailure($operation->id, $exception);
            report($exception);

            return response()->json(['message' => $this->failureMessage($exception, false),
                'credits_remaining' => $credits->wallet($request->user())['credits']], 503);
        }

        $request->session()->put('cv_checker.scan', ['signature' => CvDocumentIdentity::signature($data['job_description'], $data['resume_text']), 'assessment' => $result['ai_analysis']['assessment'] ?? null]);

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
        $background = $this->usesBackground($request);
        $operation = $request->attributes->get('ai_reserved_operation')
            ?? $credits->reserve($request->user(), 'revamp', $request->input('operation_id', (string) Str::uuid()), $data, $background);
        if ($operation->cached) {
            $payload = app(\App\Services\CvCompatibilityScore::class)->rewrite([...$operation->result, 'before' => $before,
                'after' => $matcher->compare($data['job_description'], $operation->result['rewritten_resume'])]);
            $request->session()->put('cv_checker.generated', ['text' => $payload['rewritten_resume'], 'structured_resume' => $payload['structured_resume'],
                'assessment' => $payload['assessment'] ?? null, 'report' => $this->reportFields($payload), 'original_text' => $data['resume_text'], 'job_description' => $data['job_description']]);

            return response()->json([...$payload, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
        }
        if ($background) {
            return $this->queueOperation($request, $data, $operation, 'revamp', $credits);
        }
        try {
            $scan = $request->session()->get('cv_checker.scan');
            $signature = CvDocumentIdentity::signature($data['job_description'], $data['resume_text']);
            $legacySignature = hash('sha256', json_encode(['job_description' => $data['job_description'], 'resume_text' => $data['resume_text']]));
            $baseline = in_array($scan['signature'] ?? null, [$signature, $legacySignature], true) ? ($scan['assessment'] ?? null) : null;
            $draft = $rewriter->rewrite($data['job_description'], $data['resume_text'], $data['confirmed_keywords'], $baseline);
            $payload = [...$draft, 'before' => $before, 'after' => $matcher->compare($data['job_description'], $draft['rewritten_resume']),
                'source_signature' => $signature,
                'credits_used' => config('ai_credits.costs.revamp')];
            $payload = app(\App\Services\CvCompatibilityScore::class)->rewrite($payload);
            $credits->finish($request->user(), $operation->id, $payload, $rewriter->lastUsage);
        } catch (\Throwable $exception) {
            $credits->finish($request->user(), $operation->id, null, $rewriter->lastUsage);
            $this->rememberAiFailure($operation->id, $exception);
            report($exception);

            return response()->json(['message' => $this->failureMessage($exception, true),
                'credits_remaining' => $credits->wallet($request->user())['credits']], 503);
        }

        $request->session()->put('cv_checker.generated', ['text' => $draft['rewritten_resume'], 'structured_resume' => $draft['structured_resume'],
            'assessment' => $draft['assessment'] ?? null, 'report' => $this->reportFields($draft), 'original_text' => $data['resume_text'], 'job_description' => $data['job_description']]);

        return response()->json([...$payload, 'credits_remaining' => $credits->wallet($request->user())['credits']]);
    }

    private function failureMessage(\Throwable $exception, bool $rewrite): string
    {
        if ($exception instanceof \App\Exceptions\AiResponseException) {
            if ($exception->failureReason === 'max_output_tokens') {
                return 'The AI report was cut short before it finished. Your credits were refunded. Please contact support with this reference before submitting the same request again.';
            }
            if (in_array($exception->failureReason, ['provider_auth', 'model_unavailable', 'provider_quota'], true)) {
                return 'The AI service needs attention. Your credits were refunded. Please contact support with this reference before trying again.';
            }
            if ($exception->providerStatus === 429) {
                return 'The AI provider is busy or has reached its rate limit. Your credits were refunded. Please wait a minute, then try again.';
            }
        }
        if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return 'The AI provider did not respond in time. Your credits were refunded. Please try again in a minute.';
        }
        return $rewrite
            ? 'AI rewriting is unavailable right now. Your CV is unchanged and your credits were refunded. Please try again.'
            : 'Job-to-CV ATS Detector could not be completed. Your credits were refunded. Please try again.';
    }

    private function reportFields(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['ai_changes', 'keyword_coverage', 'keyword_coverage_percentage',
            'supported_keywords_still_missing', 'post_revamp_validation', 'recommendation_details', 'remaining_warnings', 'remaining_recommendations']));
    }

    private function rememberAiFailure(int $operation, \Throwable $exception): void
    {
        $reason = $exception instanceof \App\Exceptions\AiResponseException ? $exception->failureReason
            : ($exception instanceof \Illuminate\Http\Client\ConnectionException ? 'timeout' : 'unknown');
        try {
            \Illuminate\Support\Facades\Cache::put('ai-failure:'.$operation, $reason, 3600);
        } catch (\Throwable) {
            // A diagnostic cache outage must not prevent the credit refund or
            // replace the useful provider failure response with another error.
        }
    }

    private function usesBackground(Request $request): bool
    {
        return ! $request->attributes->get('ai_background', false)
            && ((config('services.cv_ai.provider') === 'kimi' && config('services.kimi.background', true))
                || (config('services.cv_ai.provider') === 'openai' && config('services.openai.validate_revamp', false)));
    }

    private function queueOperation(Request $request, array $data, object $operation, string $feature, AiCreditService $credits)
    {
        $request->session()->put('cv_checker.pending.'.$operation->id, $data);
        if (! ($operation->pending ?? false)) {
            try {
                \App\Jobs\ProcessCvAi::dispatch($request->user()->id, $operation->id, $feature, $data,
                    $request->session()->get('cv_checker.scan'), (string) config('services.'.config('services.cv_ai.provider').'.model'),
                    config('services.cv_ai.provider'));
            } catch (\Throwable $exception) {
                report($exception);
                $credits->finish($request->user(), $operation->id, null, ['provider' => config('services.cv_ai.provider'), 'model' => config('services.'.config('services.cv_ai.provider').'.model')]);
                return response()->json(['message' => 'The scan could not be started. Your credits were refunded. Please try again.',
                    'credits_remaining' => $credits->wallet($request->user())['credits']], 503);
            }
        }
        return response()->json(['status' => 'processing', 'operation_id' => $operation->id,
            'status_url' => route('checker.operation', $operation->id, false),
            'credits_remaining' => $credits->wallet($request->user())['credits']], 202);
    }

    public function operation(Request $request, int $operation, AiCreditService $credits)
    {
        $record = DB::table('ai_operations')->where('id', $operation)->where('user_id', $request->user()->id)->first();
        abort_unless($record, 404);
        if ($record->status === 'reserved' && \Illuminate\Support\Carbon::parse($record->created_at)->lt(now()->subMinutes(10))) {
            $credits->finish($request->user(), $operation, null, ['provider' => config('services.cv_ai.provider'), 'model' => config('services.'.config('services.cv_ai.provider').'.model')]);
            $record->status = 'failed';
        }
        $wallet = $credits->wallet($request->user())['credits'];
        if ($record->status === 'reserved') {
            return response()->json(['status' => 'processing', 'credits_remaining' => $wallet], 202);
        }
        if ($record->status === 'failed') {
            $reason = \Illuminate\Support\Facades\Cache::get('ai-failure:'.$operation, 'unknown');
            $provider = DB::table('ai_usage_logs')->where('ai_operation_id', $operation)->value('provider');
            $providerName = $provider === 'openai' ? 'OpenAI' : 'Moonshot';
            $message = match ($reason) {
                'rate_limit_concurrency' => $providerName.' is still processing another request. Please wait a minute before retrying.',
                'rate_limit_rpm', 'rate_limit_local' => 'Moonshot allows three requests per minute on this project. Please wait a minute before retrying.',
                'rate_limit_tpm' => 'Moonshot rejected the request because the token-per-minute limit was reached.',
                'rate_limit_tpd' => 'The Moonshot daily token allowance has been reached. Wait for it to reset or adjust the account limit.',
                'provider_overloaded' => $providerName.' is temporarily overloaded. Please try again later.',
                'provider_quota' => $providerName.' reports insufficient API quota. Check the account balance and project budget.',
                'rate_limit' => 'Moonshot rejected the request because a rate limit was reached. Please wait a minute before retrying.',
                'max_output_tokens' => 'The AI report was cut short before it finished. Please contact support before submitting the same request again.',
                'timeout' => $providerName.' did not finish responding in time. Please wait a minute before retrying.',
                default => 'The AI could not approve or complete this request. Please review the source information before retrying.',
            };
            return response()->json(['status' => 'failed', 'message' => $message.' Your credits were refunded.',
                'credits_remaining' => $wallet], 503);
        }
        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($record->result), true, 64, JSON_THROW_ON_ERROR);
        $source = $request->session()->get('cv_checker.pending.'.$operation);
        if ($record->feature === 'analyse') {
            $request->session()->put('cv_checker.scan', ['signature' => $payload['source_signature'] ?? '',
                'assessment' => $payload['ai_analysis']['assessment'] ?? null]);
        } elseif ($source) {
            $request->session()->put('cv_checker.generated', ['text' => $payload['rewritten_resume'],
                'structured_resume' => $payload['structured_resume'], 'assessment' => $payload['assessment'] ?? null, 'report' => $this->reportFields($payload),
                'original_text' => $source['resume_text'], 'job_description' => $source['job_description']]);
        }
        return response()->json([...$payload, 'credits_remaining' => $wallet]);
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
            return $builder->import($request, 5120);
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
        if ($content === null && $request->boolean('generated') && is_array($cached) && CvDocumentIdentity::same($cached['text'], $data['resume_text'])) {
            $content = app(CvStructuredResume::class)->data($cached['structured_resume']);
        }
        $content ??= $parser->parse($data['resume_text']);
        $content = CvResumeFormat::builder($content);
        $content['template'] = in_array('ats-professional', app(PlanService::class)->templateIds($request->user()), true) ? 'ats-professional' : 'essential';
        $content['imported_text'] = $data['resume_text'];
        $draft = ['step' => 4, 'data' => $content];
        if ($request->boolean('generated')) {
            $draft['data']['section_titles'] = ['skills' => 'Skills', 'core_strengths' => 'Competencies'];
            $source = $request->validate([
                'original_resume_text' => ['required', 'string', 'max:20000'], 'job_description' => ['required', 'string', 'max:20000'],
                'ai_changes' => ['sometimes', 'array', 'max:30'], 'ai_changes.*' => ['required', 'string', 'max:1000'],
            ]);
            $draft['review_ready'] = true;
            $draft['checker_result'] = app(CvReviewComparison::class)->make($source['job_description'], $source['original_resume_text'], $data['resume_text'], $source['ai_changes'] ?? []);
            if (isset($cached['assessment'], $cached['text'], $cached['original_text'], $cached['job_description'])
                && CvDocumentIdentity::same($cached['text'], $data['resume_text']) && CvDocumentIdentity::same($cached['original_text'], $source['original_resume_text'])
                && CvDocumentIdentity::same($cached['job_description'], $source['job_description'])) {
                $draft['checker_result']['assessment'] = $cached['assessment'];
                $draft['checker_result'] += $cached['report'] ?? [];
            }
        }
        $request->session()->put('cv_builder', $draft);

        return redirect()->route('builder.start');
    }
}
