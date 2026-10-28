<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CvAiRewriter
{
    public array $lastUsage = [];

    public function analyse(string $job, string $resume): array
    {
        $this->lastUsage = [];
        $provider = 'openai';
        $key = config('services.'.$provider.'.key');
        $model = config('services.'.$provider.'.model');
        if (! $key || ! $model) {
            throw new \RuntimeException('AI is not configured.');
        }
        $schema = ['type' => 'OBJECT', 'properties' => [
            'summary' => ['type' => 'STRING'],
            'recommendations' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'assessment' => app(CvAiScanContract::class)->schema(),
        ], 'required' => ['summary', 'recommendations', 'assessment']];
        $text = $this->openAi($key, $model,
            app(CvAiPrompt::class)->instructions(false)."\nCOMPACT ANALYSIS CONTRACT: Extract every distinct employer requirement once. Return only the schema fields. Laravel derives evidence statuses, candidate profile, category points, gap recommendations and ordinary missing-requirement warnings. Do not repeat those lists or arithmetic. Warnings contain additional factual/date/document uncertainties only; do not list every missing requirement again. Keep each explanation to one short sentence and each quote to one exact short passage. Preserve all requirements and genuine gaps.",
            ['ACTION' => 'ANALYSE', 'SCORING_POLICY' => $this->scoringPolicy(), 'DATE_CALCULATION' => app(CvExperienceMatcher::class)->compare($job, $resume), 'LOCALE' => 'en-GB', 'job_description' => $job, 'original_resume' => $resume, 'current_date' => now('Africa/Johannesburg')->toDateString(), 'confirmed_keywords' => []], $schema, $this->outputBudget('analysis', 16000));

        $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        $result = Validator::make($decoded, [
            'summary' => ['required', 'string', 'max:3000'], 'recommendations' => ['present', 'array', 'max:12'],
            'recommendations.*' => ['required', 'string', 'max:1000'],
        ])->validate();
        if (isset($decoded['assessment'])) {
            $decoded['assessment'] = app(CvAiScanContract::class)->expand($decoded['assessment'], $resume);
            if (($decoded['assessment']['schema_version'] ?? '') === '2.0') {
                $decoded['assessment'] = app(CvAiV2Report::class)->sourceGuard($decoded['assessment'], $resume);
            }
            $result['assessment'] = app(CvAiAssessment::class)->normalise($decoded['assessment']);
        }
        return $result;
    }

    public function rewrite(string $job, string $resume, array $confirmed, ?array $baseline = null): array
    {
        $this->lastUsage = [];
        $provider = 'openai';
        $key = config('services.'.$provider.'.key');
        $model = config('services.'.$provider.'.model');
        if (! $key || ! $model || ! preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
            throw new \RuntimeException('AI is not configured.');
        }
        $staged = $provider === 'openai' && config('services.openai.validate_revamp', false);
        $priorUsage = [];
        if ($staged && ! $baseline) {
            $initial = $this->analyse($job, $resume);
            $baseline = $initial['assessment'] ?? null;
            if (! $baseline) throw new \RuntimeException('A baseline assessment is required before rewriting.');
            $priorUsage = $this->lastUsage;
        }
        $instructions = app(CvAiPrompt::class)->instructions(true);
        $analysis = app(CvKeywordMatcher::class)->compare($job, $resume);
        $analysis['suggestions'] = array_slice($analysis['suggestions'], 0, 20);
        $mapper = app(CvStructuredResume::class);
        $schema = ['type' => 'OBJECT', 'properties' => [
            'structured_resume' => $mapper->schema(),
            'changes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'unsupported_keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'assessment' => app(CvAiAssessment::class)->schema(),
        ], 'required' => ['structured_resume', 'changes', 'unsupported_keywords', 'assessment']];
        $reuseAssessment = $provider === 'openai' && ! $staged && ($baseline['schema_version'] ?? '') === '2.0';
        if ($reuseAssessment) {
            // The scan already extracted every requirement and source fact. Do
            // not pay to generate that same large report again during a rewrite.
            unset($schema['properties']['assessment']);
            $schema['required'] = array_values(array_diff($schema['required'], ['assessment']));
            $instructions .= "\nBASELINE REUSE OVERRIDE: original analysis is already complete in baseline_assessment. Return only the CV, changes and audit fields in the schema; DO NOT return another assessment. Keep each baseline requirement and category exactly in keyword_coverage. Laravel preserves the original evidence, original score and genuine gaps and recalculates the final report locally. Presentation cannot create a new qualification or experience.";
        }
        if ($provider === 'openai' && ! $staged) {
            $audit = app(CvAiV2Report::class)->validationSchema();
            foreach (['approved', 'issues', 'ai_changes', 'keyword_coverage'] as $field) {
                $schema['properties'][$field] = $audit['properties'][$field];
                $schema['required'][] = $field;
            }
            $instructions .= "\nReturn approved, issues, ai_changes and keyword_coverage in this SAME response, after checking the final CV against the source. This is a self-check, not an independent model review. Cover every requirement. For each change quote ONE short contiguous source excerpt in original_text and supporting_evidence and ONE short exact final field excerpt in revised_text. Use separate changes for separate edits; never join evidence with semicolons, commentary or ellipses. Prefer short changed phrases over full skill lists whose display order the application controls. Never add another assessment or repeat the CV. Return approved=false only for a blocking factual problem INSIDE the generated CV or an inaccurate change report. approved=true REQUIRES issues=[]. Genuine missing employer requirements kept OUTSIDE the CV are warnings/recommendations, not blocking issues; they must not prevent approval.";
        }
        $input = ['ACTION' => 'REVAMP', 'SCORING_POLICY' => $this->scoringPolicy(), 'LOCALE' => 'en-GB', 'job_description' => $job, 'original_resume' => $resume, 'confirmed_keywords' => array_values($confirmed),
            'current_date' => now('Africa/Johannesburg')->toDateString(),
            'analysis' => array_intersect_key($analysis, array_flip(['method', 'matched', 'missing', 'suggestions', 'experience_check']))];
        if ($baseline) {
            $input['baseline_assessment'] = [
                'requirements' => array_map(fn ($item) => array_intersect_key($item, array_flip([
                    'category', 'requirement', 'importance', 'evidence', 'original_status', 'relevance',
                ])), $baseline['requirements']),
                'professional_alignment' => $baseline['professional_alignment'] ?? null,
                'experience' => array_intersect_key($baseline['experience'], array_flip(['required_years', 'total_professional_years',
                    'documented_relevant_years', 'minimum_calendar_years', 'maximum_calendar_years', 'date_precision'])),
            ];
        }
        try {
            $text = $this->openAi($key, $model, $instructions, $input, $schema, $this->outputBudget('revamp', 18000));
        } finally {
            $this->addUsage($priorUsage);
        }
        $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        if ($reuseAssessment) {
            $data['assessment'] = $baseline;
            // Resolve only a low-severity presentation warning for a supported,
            // represented requirement. Substantive eligibility gaps remain.
            $represented = [];
            foreach ($data['keyword_coverage'] ?? [] as $item) {
                if (($item['supported'] ?? false) && ($item['represented'] ?? false)) {
                    $represented[$item['category'].'|'.mb_strtolower($item['requirement'])] = true;
                }
            }
            $supported = [];
            foreach ($baseline['requirements'] as $item) {
                if (in_array($item['original_status'], ['matched', 'demonstrated', 'directly_evidenced'], true)
                    && app(CvAiV2Report::class)->evidenceExists($item['evidence'] ?? '', $resume, $confirmed)) {
                    $supported[$item['category'].'|'.mb_strtolower($item['requirement'])] = true;
                }
            }
            $data['assessment']['warnings'] = array_values(array_filter($baseline['warnings'], fn ($warning) =>
                $warning['severity'] !== 'low' || ! isset($supported[$warning['category'].'|'.mb_strtolower($warning['requirement'])],
                    $represented[$warning['category'].'|'.mb_strtolower($warning['requirement'])])));
        }
        if (! is_array($data) || ! is_array($data['structured_resume'] ?? null)) {
            throw new \RuntimeException('AI did not return named CV fields.');
        }
        if (isset($data['assessment']) && ($data['assessment']['schema_version'] ?? '') === '2.0') {
            $data['assessment'] = app(CvAiV2Report::class)->sourceGuard($data['assessment'], $resume, $confirmed);
        }
        $normalisedAssessment = isset($data['assessment']) ? app(CvAiAssessment::class)->normalise($data['assessment'], $baseline) : null;
        // These are explicitly confirmed by the user, so retain them even if
        // the provider omits a skill. Never attribute them to a past employer.
        foreach (($staged || ($data['assessment']['schema_version'] ?? '') === '2.0') ? [] : ($analysis['requirement_groups'] ?? []) as $category => $requirements) {
            $field = match ($category) {
                'skills' => 'skills', 'competencies' => 'core_strengths', default => null
            };
            if (! $field) {
                continue;
            }
            foreach ($requirements as $requirement) {
                if (! $requirement['matched'] || mb_strlen($requirement['requirement']) > 200) {
                    continue;
                }
                $label = preg_replace('/^(?:Excellent|Strong|Sound)\s+/iu', '', $requirement['requirement']);
                if (! in_array(mb_strtolower($label), array_map('mb_strtolower', $data['structured_resume'][$field] ?? []), true)) {
                    $data['structured_resume'][$field][] = $label;
                }
            }
        }
        $applicationChanges = [];
        foreach ($confirmed as $keyword) {
            $existing = array_map('mb_strtolower', $data['structured_resume']['skills'] ?? []);
            if (! in_array(mb_strtolower($keyword), $existing, true)) {
                $data['structured_resume']['skills'][] = $keyword;
                $applicationChanges[] = $this->addedTermChange($keyword, 'Skills', $keyword, true);
            }
        }
        if ($normalisedAssessment) {
            $previous = $data['structured_resume'];
            $data['structured_resume'] = app(CvSupportedTerms::class)->apply($previous, $normalisedAssessment, $resume);
            $added = [...array_diff($data['structured_resume']['skills'], $previous['skills'] ?? []),
                ...array_diff($data['structured_resume']['core_strengths'], $previous['core_strengths'] ?? [])];
            if ($added) {
                foreach ($added as $term) {
                    foreach ($normalisedAssessment['requirements'] as $item) {
                        $label = trim(preg_replace('/^(?:Excellent|Strong|Sound)\s+/iu', '', $item['requirement']));
                        if (mb_strtolower($label) === mb_strtolower($term)) {
                            $applicationChanges[] = $this->addedTermChange($term,
                                $item['category'] === 'competencies' ? 'Competencies' : 'Skills', $item['evidence'], false, $item['requirement']);
                            break;
                        }
                    }
                }
                $data['changes'] = [...array_slice($data['changes'] ?? [], 0, 29),
                    'Highlighted source-evidenced job terms in Skills and Strengths: '.mb_substr(implode(', ', $added), 0, 850).'.'];
            }
        }
        $data['structured_resume']['role'] = app(CvProfessionalTitle::class)->preserve($resume, $data['structured_resume']['role'] ?? '');
        $mapped = $mapper->data($data['structured_resume']);
        $data['structured_resume']['core_strengths'] = CvResumeFormat::strengths($mapped['core_strengths']);
        $data['structured_resume']['competencies'] = [];
        $data['structured_resume']['references'] = $mapped['references'];
        if (is_array($data['unsupported_keywords'] ?? null)) {
            $confirmedLabels = array_map('mb_strtolower', $confirmed);
            $data['unsupported_keywords'] = array_values(array_filter($data['unsupported_keywords'], fn ($keyword) => ! is_string($keyword) || ! in_array(mb_strtolower($keyword), $confirmedLabels, true)));
        }
        $data['rewritten_resume'] = $mapper->text($mapped);
        $validated = Validator::make(is_array($data) ? $data : [], [
            'rewritten_resume' => ['required', 'string', 'max:20000'],
            'changes' => ['present', 'array', 'max:30'], 'changes.*' => ['required', 'string', 'max:1000'],
            'unsupported_keywords' => ['present', 'array', 'max:200'], 'unsupported_keywords.*' => ['required', 'string', 'max:200'],
        ])->validate();

        if (! array_filter(array_intersect_key($mapped, array_flip(['summary', 'skills', 'experience', 'education', 'custom_sections'])))) {
            throw new \RuntimeException('AI returned an empty CV.');
        }
        $postValidation = null;
        if (! $staged && $provider === 'openai' && ($data['assessment']['schema_version'] ?? '') === '2.0') {
            $data['ai_changes'] = [...($data['ai_changes'] ?? []), ...$applicationChanges];
            foreach ($applicationChanges as $change) {
                foreach ($data['keyword_coverage'] as &$coverage) {
                    if ($coverage['requirement'] === $change['related_requirement']
                        && in_array($coverage['category'], ['skills', 'competencies'], true)) {
                        $coverage['supported'] = $coverage['represented'] = true;
                        $coverage['evidence'] = $change['supporting_evidence'];
                        $coverage['explanation'] = 'Verified term included by the application.';
                    }
                }
                unset($coverage);
            }
            $postValidation = app(CvAiV2Report::class)->completeValidation($data, $baseline ?: $normalisedAssessment,
                $data['structured_resume'], $resume, $confirmed);
            foreach (['ai_changes', 'keyword_coverage', 'keyword_coverage_percentage', 'supported_keywords_still_missing'] as $field) {
                $validated[$field] = $postValidation[$field];
            }
            $validated['post_revamp_validation'] = ['approved' => true, 'issues' => [],
                'stage' => 'SOURCE_AND_COVERAGE_CHECK', 'independent_model_review' => false];
        }
        if ($staged) {
            $priorUsage = $this->lastUsage;
            try {
                $validationText = $this->openAi($key, $model, app(CvAiPrompt::class)->validationInstructions(), [
                    'ACTION' => 'VALIDATE_REVAMP', 'SCORING_POLICY' => $this->scoringPolicy(), 'LOCALE' => 'en-GB',
                    'job_description' => $job, 'original_resume' => $resume, 'confirmed_keywords' => array_values($confirmed),
                    'current_date' => now('Africa/Johannesburg')->toDateString(), 'baseline_assessment' => $baseline,
                    'revamped_cv' => $validated['rewritten_resume'], 'structured_resume' => $data['structured_resume'],
                    'proposed_changes' => $data['changes'],
                ], app(CvAiV2Report::class)->validationSchema(), $this->outputBudget('validation', 20000));
            } finally {
                $this->addUsage($priorUsage);
            }
            $validation = json_decode($validationText, true, 64, JSON_THROW_ON_ERROR);
            $postValidation = app(CvAiV2Report::class)->completeValidation($validation, $baseline,
                $data['structured_resume'], $resume, $confirmed);
            $normalisedAssessment = app(CvAiAssessment::class)->normalise(
                app(CvAiV2Report::class)->sourceGuard($postValidation['assessment'], $resume, $confirmed), $baseline);
            $data['assessment'] = $normalisedAssessment;
            $validated['ai_changes'] = $postValidation['ai_changes'];
            $validated['keyword_coverage'] = $postValidation['keyword_coverage'];
            $validated['keyword_coverage_percentage'] = $postValidation['keyword_coverage_percentage'];
            $validated['supported_keywords_still_missing'] = $postValidation['supported_keywords_still_missing'];
            $validated['post_revamp_validation'] = ['approved' => true, 'issues' => [], 'stage' => 'VALIDATE_REVAMP'];
            // User-facing summaries reflect audited changes, not the writer's unverified claims.
            $validated['changes'] = array_map(fn ($change) => mb_substr($change['section'].': '.$change['explanation'], 0, 1000),
                array_slice($postValidation['ai_changes'], 0, 30));
        }
        $validated['structured_resume'] = $data['structured_resume'];
        if (isset($data['assessment'])) {
            $assessment = $normalisedAssessment;
            $validated['assessment'] = $assessment;
            $validated += array_intersect_key($assessment, array_flip(['job_information', 'job_requirements', 'candidate_profile', 'original_analysis', 'original_score', 'revamped_score', 'score_improvement', 'warnings', 'questions_for_candidate', 'mandatory_requirements_status']));
            $validated['changes_made'] = $validated['changes'];
            $validated['recommendations'] = $assessment['recommendations'];
            $validated['remaining_warnings'] = $assessment['warnings'];
            $validated['remaining_recommendations'] = $assessment['recommendations'];
            $validated['suggestions'] = $assessment['suggestions'] ?? [];
            $validated['recommendation_details'] = $assessment['recommendation_details'] ?? [];
            $validated['unresolved_requirements'] = $assessment['unsupported_job_requirements'];
            $structured = $data['structured_resume'];
            $validated['revamped_cv'] = [
                'contact_information' => array_intersect_key($structured, array_flip(['name', 'role', 'email', 'phone', 'location', 'website'])),
                'professional_summary' => $structured['summary'], 'professional_experience' => $structured['experience'],
                'education' => $structured['education'], 'certifications' => $structured['courses'],
                'skills' => $structured['skills'], 'projects' => $structured['custom_sections'],
                'competencies_and_strengths' => $structured['core_strengths'], 'references' => $structured['references'],
            ];
        }

        return $validated;
    }

    private function outputBudget(string $stage, int $default): int
    {
        $configured = filter_var(config('services.openai.'.$stage.'_output_tokens', $default), FILTER_VALIDATE_INT);
        return max(7000, min(32000, $configured === false ? $default : $configured));
    }

    private function addedTermChange(string $term, string $section, string $evidence, bool $confirmed, ?string $requirement = null): array
    {
        return ['id' => 'application-'.substr(hash('sha256', $section.'|'.$term), 0, 12), 'section' => $section,
            'change_type' => 'supported_term_added', 'original_text' => '', 'revised_text' => $term,
            'explanation' => $confirmed ? 'Included an explicitly confirmed current skill.' : 'Highlighted a job term supported by the original CV.',
            'related_requirement' => $requirement ?? $term, 'supporting_evidence' => $evidence,
            'improves_keyword_coverage' => true, 'affects_assessment' => false];
    }

    private function scoringPolicy(): array
    {
        return ['category_weights' => CvAiAssessment::WEIGHTS, 'calculated_by' => 'Laravel',
            'requirement_importance' => ['mandatory' => 3, 'preferred' => 1, 'contextual' => 1, 'unclear' => 1],
            'source_evidence_required' => true, 'relevance_credit' => ['core' => 1, 'transferable' => 0.5, 'general' => 0.25, 'unrelated' => 0],
            'stated_only_credit' => 0.25, 'redistribute_not_applicable' => true];
    }

    private function addUsage(array $earlier): void
    {
        foreach (['input_tokens', 'output_tokens'] as $key) {
            $this->lastUsage[$key] = ($this->lastUsage[$key] ?? 0) + ($earlier[$key] ?? 0);
        }
    }

    private function openAi(string $key, string $model, string $instructions, array $input, array $schema, int $outputTokens = 14000): string
    {
        $this->lastUsage = ['provider' => 'openai', 'model' => $model, 'input_tokens' => 0, 'output_tokens' => 0];
        $isSol = $model === 'gpt-6.1-sol';
        // Allow the HTTP timeout to be caught and credits refunded before PHP terminates.
        if (PHP_SAPI !== 'cli' && (int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < 150) {
            set_time_limit(150);
        }
        $started = microtime(true);
        try {
            $response = Http::connectTimeout(10)->timeout(120)->withToken($key)->post('https://api.openai.com/v1/responses', [
            'model' => $model,
            ...($isSol ? ['reasoning' => ['effort' => 'low']] : []),
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
            'store' => false,
            'max_output_tokens' => $outputTokens,
            'text' => [...($isSol ? ['verbosity' => 'low'] : []),
                'format' => ['type' => 'json_schema', 'name' => 'tailored_cv', 'strict' => true, 'schema' => $this->openAiSchema($schema)]],
            ]);
        } finally {
            // Counts and timings only: never log the CV, advert, API key or provider error text.
            request()->attributes->set('ai_request_metrics', [
                'model' => $model, 'action' => $input['ACTION'] ?? '',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'instruction_characters' => mb_strlen($instructions),
                'input_characters' => mb_strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'max_output_tokens' => $outputTokens,
            ]);
        }
        $metrics = request()->attributes->get('ai_request_metrics');
        $metrics['http_status'] = $response->status();
        $requestId = $response->header('x-request-id');
        if (is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $requestId)) $metrics['request_id'] = $requestId;
        request()->attributes->set('ai_request_metrics', $metrics);
        $this->lastUsage = ['provider' => 'openai', 'model' => $response->json('model') ?? $model,
            'input_tokens' => $response->json('usage.input_tokens', 0), 'output_tokens' => $response->json('usage.output_tokens', 0)];
        if (! $response->successful()) {
            // Capture HTTP status without exposing provider messages, CV text or keys.
            $reason = match (true) {
                in_array($response->status(), [401, 403], true) => 'provider_auth',
                $response->status() === 404 => 'model_unavailable',
                $response->status() === 429 && in_array($response->json('error.code') ?? $response->json('error.type'),
                    ['insufficient_quota', 'billing_hard_limit_reached'], true) => 'provider_quota',
                $response->status() === 429 => 'rate_limit',
                $response->status() >= 500 => 'provider_overloaded',
                default => 'http_error',
            };
            throw new \App\Exceptions\AiResponseException($response->status(), $reason);
        }
        if (! $response->successful() || $response->json('status') !== 'completed') {
            $reason = $response->json('incomplete_details.reason');
            throw new \App\Exceptions\AiResponseException($response->status(), $reason === 'max_output_tokens' ? 'max_output_tokens' : 'incomplete_response');
        }
        $text = '';
        foreach ($response->json('output', []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'refusal') {
                    throw new \RuntimeException('AI could not rewrite this CV.');
                }
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= $part['text'] ?? '';
                }
            }
        }
        if (! trim($text)) {
            throw new \RuntimeException('AI returned no CV.');
        }

        return $text;
    }

    private function openAiSchema(array $schema): array
    {
        $schema['type'] = strtolower($schema['type']);
        if ($schema['type'] === 'object') {
            $schema['additionalProperties'] = false;
            foreach ($schema['properties'] as $name => $property) {
                $schema['properties'][$name] = $this->openAiSchema($property);
            }
            $schema['required'] = array_keys($schema['properties']);
        } elseif ($schema['type'] === 'array') {
            $schema['items'] = $this->openAiSchema($schema['items']);
        }
        if ($schema['nullable'] ?? false) {
            $schema['type'] = [$schema['type'], 'null'];
            if (isset($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }
        unset($schema['nullable']);

        return $schema;
    }
}
