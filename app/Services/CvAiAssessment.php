<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CvAiAssessment
{
    public const WEIGHTS = ['education' => 25, 'certifications' => 10, 'skills' => 25, 'competencies' => 15, 'experience' => 15, 'responsibilities' => 10];

    public function schema(): array
    {
        $string = ['type' => 'STRING'];
        $strings = ['type' => 'ARRAY', 'items' => $string];
        $object = fn (array $properties) => ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];
        $enum = fn (array $values) => ['type' => 'STRING', 'enum' => $values];
        $statuses = ['matched', 'partially_matched', 'not_evidenced', 'uncertain', 'demonstrated', 'stated_only', 'partially_evidenced', 'directly_evidenced', 'transferable_experience'];
        $categories = [];
        foreach (self::WEIGHTS as $name => $weight) {
            $categories[$name] = $object([
                'applicability' => $enum(['applicable', 'not_applicable']),
                'original_points' => ['type' => 'NUMBER'], 'revamped_points' => ['type' => 'NUMBER'],
                'explanation' => $string,
            ]);
        }

        return $object([
            'job_information' => $object(array_fill_keys(['title', 'industry', 'seniority', 'employment_type'], $string)),
            'candidate_profile' => $object(array_fill_keys(['qualifications', 'certifications', 'skills', 'competencies', 'experience', 'projects'], $strings)),
            'requirements' => ['type' => 'ARRAY', 'items' => $object([
                'category' => $enum(array_keys(self::WEIGHTS)), 'requirement' => $string,
                'importance' => $enum(['mandatory', 'preferred']), 'evidence' => $string,
                'relevance' => $enum(CvIndustryRelevance::RELEVANCE),
                'original_status' => $enum($statuses), 'revamped_status' => $enum($statuses),
            ])],
            'categories' => $object($categories),
            'experience' => $object([
                'required_years' => ['type' => 'NUMBER', 'nullable' => true],
                'documented_relevant_years' => ['type' => 'NUMBER', 'nullable' => true],
                'total_professional_years' => ['type' => 'NUMBER', 'nullable' => true],
                'explanation' => $string, 'date_warnings' => $strings,
            ]),
            'warnings' => ['type' => 'ARRAY', 'items' => $object([
                'severity' => $enum(['critical', 'high', 'medium', 'low']),
                'category' => $enum(array_keys(self::WEIGHTS)), 'requirement' => $string,
                'explanation' => $string, 'evidence' => $string, 'recommended_action' => $string,
            ])],
            'verified_information_to_highlight' => $strings, 'information_requiring_confirmation' => $strings,
            'unsupported_job_requirements' => $strings, 'questions_for_candidate' => $strings,
            'recommendations' => $strings,
            ...app(CvIndustryRelevance::class)->schema(),
        ]);
    }

    public function normalise(array $data, ?array $baseline = null): array
    {
        $rules = ['requirements' => ['present', 'array', 'max:150'], 'warnings' => ['present', 'array', 'max:60'],
            'experience.required_years' => ['present', 'nullable', 'numeric', 'min:0'],
            'experience.documented_relevant_years' => ['present', 'nullable', 'numeric', 'min:0'],
            'experience.total_professional_years' => ['present', 'nullable', 'numeric', 'min:0'],
            'experience.explanation' => ['present', 'string', 'max:3000'], 'experience.date_warnings' => ['present', 'array', 'max:30']];
        foreach (['job_information.title', 'job_information.industry', 'job_information.seniority', 'job_information.employment_type'] as $key) {
            $rules[$key] = ['present', 'nullable', 'string', 'max:200'];
        }
        foreach (['qualifications', 'certifications', 'skills', 'competencies', 'experience', 'projects'] as $key) {
            $rules['candidate_profile.'.$key] = ['present', 'array', 'max:100'];
            $rules['candidate_profile.'.$key.'.*'] = ['string', 'max:1000'];
        }
        foreach (self::WEIGHTS as $name => $weight) {
            $rules['categories.'.$name.'.applicability'] = ['required', Rule::in(['applicable', 'not_applicable'])];
            foreach (['original_points', 'revamped_points'] as $key) {
                $rules['categories.'.$name.'.'.$key] = ['required', 'numeric', 'between:0,'.$weight];
            }
            $rules['categories.'.$name.'.explanation'] = ['present', 'string', 'max:2000'];
        }
        foreach (['requirement', 'evidence'] as $key) {
            $rules['requirements.*.'.$key] = ['present', 'nullable', 'string', 'max:1500'];
        }
        $rules['requirements.*.category'] = ['required', Rule::in(array_keys(self::WEIGHTS))];
        $rules['requirements.*.importance'] = ['required', Rule::in(['mandatory', 'preferred'])];
        foreach (['original_status', 'revamped_status'] as $key) {
            $rules['requirements.*.'.$key] = ['required', Rule::in(['matched', 'partially_matched', 'not_evidenced', 'uncertain', 'demonstrated', 'stated_only', 'partially_evidenced', 'directly_evidenced', 'transferable_experience'])];
        }
        foreach (['verified_information_to_highlight', 'information_requiring_confirmation', 'unsupported_job_requirements', 'questions_for_candidate', 'recommendations'] as $key) {
            $rules[$key] = ['present', 'array', 'max:100'];
            $rules[$key.'.*'] = ['string', 'max:1000'];
        }
        $rules['warnings.*.severity'] = ['required', Rule::in(['critical', 'high', 'medium', 'low'])];
        $rules['warnings.*.category'] = ['required', Rule::in(array_keys(self::WEIGHTS))];
        foreach (['requirement', 'explanation', 'evidence', 'recommended_action'] as $key) {
            $rules['warnings.*.'.$key] = ['present', 'nullable', 'string', 'max:1500'];
        }
        $rules['requirements.*.relevance'] = ['required_with:professional_alignment', Rule::in(CvIndustryRelevance::RELEVANCE)];
        foreach (['target_profession', 'candidate_background', 'score_validation_explanation'] as $key) {
            $rules[$key] = ['sometimes', 'string', 'max:3000'];
        }
        $rules['professional_alignment'] = ['sometimes', Rule::in(CvIndustryRelevance::ALIGNMENTS)];
        $rules['false_positive_risk'] = ['sometimes', Rule::in(['low', 'medium', 'high'])];
        foreach (['core_skills_matched', 'transferable_skills_matched', 'general_competencies_matched', 'unrelated_skills', 'missing_core_requirements', 'cross_industry_warnings'] as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:100'];
            $rules[$key.'.*'] = ['string', 'max:1500'];
        }
        foreach (['qualification_relevance.status', 'qualification_relevance.explanation', 'relevant_experience.explanation'] as $key) {
            $rules[$key] = ['sometimes', 'string', 'max:3000'];
        }
        foreach (['direct_years', 'related_years', 'transferable_years'] as $key) {
            $rules['relevant_experience.'.$key] = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'];
        }
        $data = Validator::make($data, $rules)->validate();
        if (($baseline['scoring_method'] ?? '') === CvIndustryRelevance::VERSION) {
            // A rewrite cannot silently remove specialist gaps or promote generic evidence.
            $revised = [];
            foreach ($data['requirements'] as $item) {
                $revised[$item['category'].'|'.mb_strtolower($item['requirement'])] = $item;
            }
            $data['requirements'] = array_map(function ($item) use ($revised) {
                $match = $revised[$item['category'].'|'.mb_strtolower($item['requirement'])] ?? null;
                if ($match && trim($item['evidence'] ?? '') !== '') $item['revamped_status'] = $match['revamped_status'];
                return $item;
            }, $baseline['requirements']);
            foreach (['target_profession', 'candidate_background', 'professional_alignment', 'false_positive_risk', 'missing_core_requirements', 'cross_industry_warnings'] as $key) {
                if (array_key_exists($key, $baseline)) $data[$key] = $baseline[$key];
            }
        }
        $data = app(CvIndustryRelevance::class)->score($data);
        if ($baseline) {
            // The original score must not drift between scan and rewrite of the same source.
            foreach (self::WEIGHTS as $name => $weight) {
                $data['categories'][$name]['original_points'] = $baseline['categories'][$name]['original_points'];
                $data['categories'][$name]['applicability'] = $baseline['categories'][$name]['applicability'];
            }
        }
        foreach (['education', 'certifications', 'experience'] as $name) {
            // Rewording cannot add qualifications, certifications or employment time.
            $data['categories'][$name]['revamped_points'] = $data['categories'][$name]['original_points'];
        }
        $applicable = array_filter(self::WEIGHTS, fn ($weight, $name) => $data['categories'][$name]['applicability'] === 'applicable', ARRAY_FILTER_USE_BOTH);
        if (! array_sum($applicable)) {
            throw new \RuntimeException('The AI assessment has no applicable scoring categories.');
        }
        $original = $revised = $weights = [];
        $originalTotal = $revisedTotal = 0;
        foreach (self::WEIGHTS as $name => $weight) {
            $weights[$name] = isset($applicable[$name]) ? round($weight / array_sum($applicable) * 100, 4) : 0;
            $original[$name] = round($data['categories'][$name]['original_points'] / $weight * $weights[$name], 2);
            $revised[$name] = round($data['categories'][$name]['revamped_points'] / $weight * $weights[$name], 2);
            if (isset($applicable[$name])) {
                $originalTotal += $data['categories'][$name]['original_points'] / array_sum($applicable) * 100;
                $revisedTotal += $data['categories'][$name]['revamped_points'] / array_sum($applicable) * 100;
            }
        }
        // Round totals once, distributing the rounding cent to a nonzero category.
        $originalKey = array_search(max($original), $original, true);
        $revisedKey = array_search(max($revised), $revised, true);
        $original[$originalKey] = round($original[$originalKey] + round($originalTotal, 2) - array_sum($original), 2);
        $revised[$revisedKey] = round($revised[$revisedKey] + round($revisedTotal, 2) - array_sum($revised), 2);
        $original['total'] = min(100, round($originalTotal, 2));
        $revised['total'] = min(100, round($revisedTotal, 2));
        $groups = [];
        foreach ($data['requirements'] as $item) {
            $groups[$item['category']][] = $item;
        }
        $mandatory = array_filter($data['requirements'], fn ($item) => $item['importance'] === 'mandatory');
        $unmet = array_filter($mandatory, fn ($item) => ! in_array($item['original_status'], ['matched', 'demonstrated', 'directly_evidenced'], true));
        return [...$data, 'job_requirements' => $groups, 'original_analysis' => $groups,
            'original_score' => $original, 'revamped_score' => $revised, 'category_weights' => $weights,
            'mandatory_requirements_status' => ! $mandatory ? 'not_specified' : ($unmet ? 'review_required' : 'evidenced'),
            'score_improvement' => round($revised['total'] - $original['total'], 2),
            'score_label' => 'ATSDetector estimated compatibility; not an official employer ATS score'];
    }
}
