<?php

namespace App\Services;

class CvAiV2Report
{
    public function changeSchema(): array
    {
        $properties = array_fill_keys(['id', 'section', 'change_type', 'original_text', 'revised_text', 'explanation', 'related_requirement', 'supporting_evidence'], ['type' => 'STRING']);
        $properties['original_text']['description'] = 'One short exact contiguous original CV excerpt, or empty for an addition. Never join excerpts, summarise them or add ellipses.';
        $properties['revised_text']['description'] = 'One short exact contiguous final structured CV field excerpt. Prefer one changed phrase or bullet, not a complete multi-item list.';
        $properties['supporting_evidence']['description'] = 'One short exact contiguous original CV quote or an exact confirmed current skill. No narrative, source references, joined excerpts or ellipses.';
        $properties += ['improves_keyword_coverage' => ['type' => 'BOOLEAN'], 'affects_assessment' => ['type' => 'BOOLEAN']];
        return ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];
    }

    public function assessmentFields(): array
    {
        $string = ['type' => 'STRING'];
        $object = fn ($properties) => ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];
        return [
            'schema_version' => ['type' => 'STRING', 'enum' => ['2.0']],
            'document_quality' => $object(['extraction_limitations' => ['type' => 'ARRAY', 'items' => $string], 'uncertainties' => ['type' => 'ARRAY', 'items' => $string]]),
            'suggestions' => ['type' => 'ARRAY', 'items' => $object([
                ...array_fill_keys(['id', 'section', 'problem', 'improvement', 'reason', 'related_requirement'], $string),
                'can_apply_now' => ['type' => 'BOOLEAN'],
            ])],
        ];
    }

    public function validationSchema(): array
    {
        $properties = [
            'approved' => ['type' => 'BOOLEAN'], 'issues' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'assessment' => app(CvAiAssessment::class)->schema(),
            'ai_changes' => ['type' => 'ARRAY', 'items' => $this->changeSchema()],
            'keyword_coverage' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                'category' => ['type' => 'STRING', 'enum' => array_keys(CvAiAssessment::WEIGHTS)],
                'requirement' => ['type' => 'STRING'], 'evidence' => ['type' => 'STRING'],
                'supported' => ['type' => 'BOOLEAN'], 'represented' => ['type' => 'BOOLEAN'], 'explanation' => ['type' => 'STRING'],
            ], 'required' => ['category', 'requirement', 'evidence', 'supported', 'represented', 'explanation']]],
        ];
        $properties['approved']['description'] = 'True when the generated CV is factually safe and the change report is accurate. Missing employer requirements outside the CV do not prevent approval.';
        $properties['issues']['description'] = 'Blocking factual problems INSIDE the generated CV or its change report only. MUST be [] when approved=true. Never list missing employer requirements here; put those in assessment warnings/recommendations.';
        return ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];
    }

    public function evidenceExists(string $quote, string $source, array $confirmed = []): bool
    {
        $quote = $this->words($quote);
        if ($quote === '') return false;
        return str_contains(' '.$this->words($source).' ', ' '.$quote.' ')
            || in_array($quote, array_map($this->words(...), $confirmed), true);
    }

    public function sourceGuard(array $assessment, string $source, array $confirmed = []): array
    {
        foreach ($assessment['requirements'] as &$item) {
            if (in_array($item['match_classification'] ?? '', ['CONFIRMED_GAP', 'NOT_EVIDENCED'], true)) {
                $item['original_status'] = $item['revamped_status'] = 'not_evidenced';
            }
            $fromCv = $this->evidenceExists($item['evidence'] ?? '', $source);
            $item['evidence_source'] = $fromCv ? 'original_cv' : 'user_confirmed';
            if (! $fromCv && (! in_array($item['category'], ['skills', 'competencies'], true)
                || ! $this->evidenceExists($item['evidence'] ?? '', $source, $confirmed))) {
                $item['evidence_source'] = 'not_evidenced';
                $item['match_classification'] = 'NOT_EVIDENCED';
                $item['evidence'] = '';
                $item['original_status'] = $item['revamped_status'] = 'not_evidenced';
            }
        }
        unset($item);
        $unique = [];
        foreach ($assessment['requirements'] as $item) {
            $unique[$item['category'].'|'.$this->words($item['requirement'])] ??= $item;
        }
        $assessment['requirements'] = array_values($unique);
        $dates = app(CvExperienceMatcher::class)->compare('', $source);
        $assessment['experience']['total_professional_years'] = $dates['cv_years'];
        $assessment['experience']['calculation_source'] = $dates['calculation_source'];
        if ($dates['cv_years'] === null) {
            $assessment['experience']['documented_relevant_years'] = null;
        } elseif (is_numeric($assessment['experience']['documented_relevant_years'] ?? null)) {
            $assessment['experience']['documented_relevant_years'] = min((float) $dates['cv_years'], (float) $assessment['experience']['documented_relevant_years']);
        }
        $assessment['experience']['date_warnings'] = array_values(array_unique([
            ...($assessment['experience']['date_warnings'] ?? []),
            ...array_map(fn ($period) => 'Clarify employment dates: '.$period, $dates['unreadable_dates']),
        ]));
        return $assessment;
    }

    public function gaps(array $assessment, ?array $baseline = null, bool $revised = false): array
    {
        $details = [];
        $full = ['matched', 'demonstrated', 'directly_evidenced'];
        foreach ($assessment['requirements'] as &$requirement) {
            $requirement['id'] = 'requirement-'.substr(hash('sha256', $requirement['category'].'|'.$this->words($requirement['requirement'])), 0, 12);
        }
        unset($requirement);
        foreach ($assessment['requirements'] as $index => $item) {
            $originalSupported = in_array($item['original_status'], $full, true) && trim($item['evidence'] ?? '') !== '';
            $remaining = ! $originalSupported || ($revised && ! in_array($item['revamped_status'], $full, true));
            if (! $remaining) continue;
            $confirmedGap = ($item['match_classification'] ?? '') === 'CONFIRMED_GAP' && trim($item['evidence'] ?? '') !== '';
            $action = match ($item['category']) {
                'education' => 'Confirm the relevant qualification and completion details if held; otherwise consider the required qualification or an employer-accepted equivalent pathway.',
                'certifications' => 'Confirm the certification, licence or registration and its validity if held; otherwise investigate the required credential or registration pathway.',
                'experience', 'responsibilities' => 'Provide genuine duties, dates and examples if available; otherwise consider relevant entry-level work, internships or practical projects.',
                'competencies' => 'Provide a genuine workplace or project example; otherwise develop this competency through relevant practical activities.',
                default => 'Confirm this skill with a genuine example if held; otherwise develop it through relevant training or practical projects.',
            };
            if ($confirmedGap) {
                $action = 'The source confirms this gap. Consider the required qualification, credential, relevant training or practical experience for this requirement; do not add it as an existing capability.';
            }
            $details[] = ['id' => 'recommendation-'.($index + 1), 'category' => $item['category'], 'requirement' => $item['requirement'],
                'why_it_matters' => 'The employer lists this as a '.$item['importance'].' requirement.',
                'evidence_status' => $confirmedGap ? 'confirmed_gap' : ($originalSupported ? 'presentation_gap' : $item['original_status']), 'action' => $action,
                'priority' => $item['importance'] === 'mandatory' ? 'high' : 'medium', 'confirmation_required' => ! $originalSupported && ! $confirmedGap,
                'can_improve_current_cv' => $originalSupported, 'future_development_if_absent' => ! $originalSupported];
            $tip = $item['requirement'].': '.$action;
            if (! in_array($tip, $assessment['recommendations'], true)) $assessment['recommendations'][] = $tip;
            if (! $originalSupported && ! in_array($item['requirement'], $assessment['unsupported_job_requirements'], true)) {
                $assessment['unsupported_job_requirements'][] = $item['requirement'];
            }
            $hasWarning = array_filter($assessment['warnings'], fn ($warning) => $warning['requirement'] === $item['requirement'] && $warning['category'] === $item['category']);
            if ($confirmedGap && $item['importance'] === 'mandatory') {
                foreach ($assessment['warnings'] as &$warning) {
                    if ($warning['category'] === $item['category'] && $warning['requirement'] === $item['requirement']) {
                        $warning['severity'] = 'critical';
                        $warning['explanation'] = 'The source explicitly confirms that this mandatory requirement is not met.';
                        $warning['recommended_action'] = $action;
                    }
                }
                unset($warning);
            }
            $priorWarning = array_values(array_filter($baseline['warnings'] ?? [], fn ($warning) => $warning['category'] === $item['category'] && $warning['requirement'] === $item['requirement'] && in_array($warning['severity'], ['critical', 'high'], true)));
            if (! $hasWarning && $priorWarning) {
                $assessment['warnings'][] = $priorWarning[0];
                $hasWarning = true;
            }
            if (! $hasWarning) {
                $assessment['warnings'][] = ['severity' => $item['importance'] === 'mandatory' ? ($confirmedGap ? 'critical' : 'high') : 'medium',
                    'category' => $item['category'], 'requirement' => $item['requirement'],
                    'explanation' => $originalSupported ? 'Supported information is not yet clearly represented in the CV.' : 'The original CV does not fully establish this requirement; this does not prove the candidate lacks it.',
                    'evidence' => $item['evidence'], 'recommended_action' => $action];
            }
        }
        foreach ($assessment['warnings'] as &$warning) {
            $warning['id'] = 'warning-'.substr(hash('sha256', $warning['category'].'|'.$this->words($warning['requirement'])), 0, 12);
        }
        unset($warning);
        $assessment['recommendation_details'] = $details;
        $assessment['remaining_warnings'] = $assessment['warnings'];
        $assessment['remaining_recommendations'] = $assessment['recommendations'];
        return $assessment;
    }

    public function completeValidation(array $validation, array $baseline, array $resume, string $source, array $confirmed): array
    {
        $validated = \Illuminate\Support\Facades\Validator::make($validation, [
            'approved' => ['required', 'boolean'], 'issues' => ['present', 'array', 'max:100'], 'issues.*' => ['string', 'max:2000'],
            'assessment' => ['required', 'array'], 'keyword_coverage' => ['present', 'array', 'max:150'],
            'keyword_coverage.*.category' => ['required', \Illuminate\Validation\Rule::in(array_keys(CvAiAssessment::WEIGHTS))],
            'keyword_coverage.*.requirement' => ['required', 'string', 'max:1500'],
            'keyword_coverage.*.evidence' => ['present', 'nullable', 'string', 'max:1500'],
            'keyword_coverage.*.supported' => ['required', 'boolean'], 'keyword_coverage.*.represented' => ['required', 'boolean'],
            'keyword_coverage.*.explanation' => ['present', 'nullable', 'string', 'max:2000'],
            'ai_changes' => ['present', 'array', 'max:100'],
            ...$this->changeRules(),
        ])->validate();
        if (! $validated['approved'] || $validated['issues']) throw new \RuntimeException('The revamped CV failed factual validation.');
        $text = app(CvStructuredResume::class)->text(app(CvStructuredResume::class)->data($resume));
        $byRequirement = [];
        foreach ($validated['keyword_coverage'] as $item) {
            $key = $item['category'].'|'.$this->words($item['requirement']);
            if (isset($byRequirement[$key])) throw new \RuntimeException('Duplicate requirement in validation.');
            $byRequirement[$key] = $item;
        }
        $supported = $represented = 0;
        $missing = [];
        if (count($byRequirement) !== count($baseline['requirements'])) throw new \RuntimeException('Validation changed the original requirement set.');
        foreach ($baseline['requirements'] as $item) {
            $key = $item['category'].'|'.$this->words($item['requirement']);
            $check = $byRequirement[$key] ?? null;
            if (! $check) throw new \RuntimeException('Validation omitted an original job requirement.');
            $isSupported = in_array($item['original_status'], ['matched', 'demonstrated', 'directly_evidenced'], true)
                && $this->evidenceExists($item['evidence'] ?? '', $source, $confirmed);
            // A confirmed current skill may be added after the original scan.
            // It cannot change the original score or establish past duties/credentials.
            $isSupported = $isSupported || (in_array($item['category'], ['skills', 'competencies'], true)
                && in_array($this->words($item['requirement']), array_map($this->words(...), $confirmed), true));
            if ($check['represented'] && ! $check['supported']) throw new \RuntimeException('Validation counted an unsupported requirement as covered.');
            if ($check['supported'] && ! $isSupported) throw new \RuntimeException('Validation promoted unsupported evidence.');
            if ($isSupported) {
                $supported++;
                if ($check['supported'] && $check['represented']) $represented++;
                else $missing[] = $item['requirement'];
            }
        }
        if ($missing) throw new \RuntimeException('The revamped CV omitted supported job requirements.');
        if ($this->words($source) !== $this->words($text) && ! $validated['ai_changes']) {
            throw new \RuntimeException('A changed CV requires a change report.');
        }
        foreach ($validated['ai_changes'] as $change) {
            if ($change['original_text'] !== '' && ! $this->evidenceExists($change['original_text'], $source)) {
                throw new \RuntimeException('Change report does not quote the original CV.');
            }
            if ($change['revised_text'] !== '' && ! $this->evidenceExists($change['revised_text'], $text)) {
                throw new \RuntimeException('Change report does not quote the final CV.');
            }
            if ($change['supporting_evidence'] !== '' && ! $this->evidenceExists($change['supporting_evidence'], $source, $confirmed)) {
                throw new \RuntimeException('Change report evidence is unsupported.');
            }
        }
        return [...$validated, 'keyword_coverage_percentage' => $supported ? round(100 * $represented / $supported, 2) : null,
            'supported_requirements' => $supported, 'supported_requirements_represented' => $represented, 'supported_keywords_still_missing' => $missing];
    }

    private function changeRules(): array
    {
        $rules = [];
        foreach (['id', 'section', 'change_type', 'original_text', 'revised_text', 'explanation', 'related_requirement', 'supporting_evidence'] as $key) {
            $rules['ai_changes.*.'.$key] = ['present', 'nullable', 'string', 'max:3000'];
        }
        foreach (['improves_keyword_coverage', 'affects_assessment'] as $key) $rules['ai_changes.*.'.$key] = ['required', 'boolean'];
        return $rules;
    }

    private function words(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}+#]+/u', ' ', mb_strtolower($text)));
    }
}
