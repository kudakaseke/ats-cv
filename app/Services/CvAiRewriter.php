<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CvAiRewriter
{
    public array $lastUsage = [];

    public function analyse(string $job, string $resume, ?string $industry = null): array
    {
        $this->lastUsage = [];
        $key = config('services.openai.key');
        $model = config('services.openai.model');
        if (! $key || ! $model) {
            throw new \RuntimeException('OpenAI is not configured.');
        }
        $schema = ['type' => 'OBJECT', 'properties' => [
            'summary' => ['type' => 'STRING'],
            'recommendations' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'assessment' => app(CvAiAssessment::class)->schema(),
        ], 'required' => ['summary', 'recommendations', 'assessment']];
        $text = $this->openAi($key, $model,
            app(CvAiPrompt::class)->instructions(false),
            ['selected_industry' => config('industries.'.$industry, 'Use the job description'), 'job_description' => $job, 'original_resume' => $resume, 'current_date' => now('Africa/Johannesburg')->toDateString(), 'confirmed_keywords' => []], $schema, 7000);

        $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        $result = Validator::make($decoded, [
            'summary' => ['required', 'string', 'max:3000'], 'recommendations' => ['present', 'array', 'max:12'],
            'recommendations.*' => ['required', 'string', 'max:1000'],
        ])->validate();
        if (isset($decoded['assessment'])) {
            $result['assessment'] = app(CvAiAssessment::class)->normalise($decoded['assessment']);
        }
        return $result;
    }

    public function rewrite(string $job, string $resume, array $confirmed, ?array $baseline = null, ?string $industry = null): array
    {
        $this->lastUsage = [];
        $provider = config('services.cv_ai.provider', 'openai');
        if (! in_array($provider, ['openai', 'gemini'], true)) {
            throw new \RuntimeException('Unsupported AI provider.');
        }
        $key = config('services.'.$provider.'.key');
        $model = config('services.'.$provider.'.model');
        if (! $key || ! $model || ! preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
            throw new \RuntimeException('AI is not configured.');
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
        $input = ['selected_industry' => config('industries.'.$industry, 'Use the job description'), 'job_description' => $job, 'original_resume' => $resume, 'confirmed_keywords' => array_values($confirmed),
            'current_date' => now('Africa/Johannesburg')->toDateString(),
            'analysis' => array_intersect_key($analysis, array_flip(['method', 'matched', 'missing', 'suggestions', 'experience_check']))];
        if ($baseline) {
            $input['baseline_assessment'] = array_intersect_key($baseline, array_flip(['requirements', 'categories', 'experience', 'professional_alignment', 'target_profession', 'candidate_background', 'missing_core_requirements', 'cross_industry_warnings']));
        }
        if ($provider === 'openai') {
            $text = $this->openAi($key, $model, $instructions, $input, $schema);
        } else {
            $response = Http::connectTimeout(10)->timeout(60)->withHeaders(['x-goog-api-key' => $key])->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $instructions]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => $schema, 'temperature' => 0.2, 'maxOutputTokens' => 12000],
                ]);
            if (! $response->successful()) {
                throw new \RuntimeException('AI request failed.');
            }
            $usage = $response->json('usageMetadata');
            $this->lastUsage = ['provider' => 'gemini', 'model' => $model];
            if (isset($usage['promptTokenCount'], $usage['candidatesTokenCount'])) {
                $this->lastUsage += ['input_tokens' => $usage['promptTokenCount'],
                    'cached_input_tokens' => $usage['cachedContentTokenCount'] ?? 0,
                    'output_tokens' => $usage['candidatesTokenCount']];
            }
            $candidate = $response->json('candidates.0');
            if (($candidate['finishReason'] ?? '') !== 'STOP') {
                throw new \RuntimeException('AI response was incomplete.');
            }
            $text = collect($candidate['content']['parts'] ?? [])->reject(fn ($part) => $part['thought'] ?? false)->pluck('text')->implode('');
        }
        $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['structured_resume'] ?? null)) {
            throw new \RuntimeException('AI did not return named CV fields.');
        }
        $normalisedAssessment = isset($data['assessment']) ? app(CvAiAssessment::class)->normalise($data['assessment'], $baseline) : null;
        // These are explicitly confirmed by the user, so retain them even if
        // the provider omits a skill. Never attribute them to a past employer.
        foreach ($analysis['requirement_groups'] ?? [] as $category => $requirements) {
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
        foreach ($confirmed as $keyword) {
            $existing = array_map('mb_strtolower', $data['structured_resume']['skills'] ?? []);
            if (! in_array(mb_strtolower($keyword), $existing, true)) {
                $data['structured_resume']['skills'][] = $keyword;
            }
        }
        if ($normalisedAssessment) {
            $previous = $data['structured_resume'];
            $data['structured_resume'] = app(CvSupportedTerms::class)->apply($previous, $normalisedAssessment, $resume);
            $added = [...array_diff($data['structured_resume']['skills'], $previous['skills'] ?? []),
                ...array_diff($data['structured_resume']['core_strengths'], $previous['core_strengths'] ?? [])];
            if ($added) {
                $data['changes'] = [...array_slice($data['changes'] ?? [], 0, 29),
                    'Highlighted source-evidenced job terms in Skills and Strengths: '.mb_substr(implode(', ', $added), 0, 850).'.'];
            }
        }
        $data['structured_resume']['role'] = app(CvProfessionalTitle::class)->preserve($resume, $data['structured_resume']['role'] ?? '');
        $mapped = $mapper->data($data['structured_resume']);
        $mapped['skills'] = (new CvTechnicalSkills)->format($mapped['skills'], $mapped['role'], $resume);
        $data['structured_resume']['skills'] = array_values(array_filter(array_map('trim', preg_split(str_contains($mapped['skills'], ':') ? '/\R/u' : '/[,;\r\n|]+/u', $mapped['skills']))));
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
        $validated['structured_resume'] = $data['structured_resume'];
        if (isset($data['assessment'])) {
            $assessment = $normalisedAssessment;
            $validated['assessment'] = $assessment;
            $validated += array_intersect_key($assessment, array_flip(['job_information', 'job_requirements', 'candidate_profile', 'original_analysis', 'original_score', 'revamped_score', 'score_improvement', 'warnings', 'questions_for_candidate', 'mandatory_requirements_status']));
            $validated['changes_made'] = $validated['changes'];
            $validated['recommendations'] = $assessment['recommendations'];
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

    private function openAi(string $key, string $model, string $instructions, array $input, array $schema, int $outputTokens = 14000): string
    {
        $response = Http::connectTimeout(10)->timeout(120)->withToken($key)->post('https://api.openai.com/v1/responses', [
            'model' => $model,
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
            'store' => false,
            'max_output_tokens' => $outputTokens,
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'tailored_cv', 'strict' => true, 'schema' => $this->openAiSchema($schema)]],
        ]);
        $usage = $response->json('usage');
        $this->lastUsage = ['provider' => 'openai', 'model' => $model];
        if (isset($usage['input_tokens'], $usage['output_tokens'])) {
            $this->lastUsage += ['input_tokens' => $usage['input_tokens'],
                'cached_input_tokens' => $usage['input_tokens_details']['cached_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens']];
        }
        if (! $response->successful()) {
            // Capture HTTP status without exposing provider messages, CV text or keys.
            throw new \App\Exceptions\AiResponseException($response->status(), 'http_error');
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
