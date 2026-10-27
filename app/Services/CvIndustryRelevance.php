<?php

namespace App\Services;

class CvIndustryRelevance
{
    public const VERSION = 'relevance-weighted-requirements-v1';

    public const ALIGNMENTS = ['DIRECT_ALIGNMENT', 'RELATED_FIELD', 'TRANSFERABLE_BACKGROUND', 'LIMITED_ALIGNMENT', 'INSUFFICIENT_EVIDENCE'];

    public const RELEVANCE = ['core', 'transferable', 'general', 'unrelated'];

    public function schema(): array
    {
        $string = ['type' => 'STRING'];
        $strings = ['type' => 'ARRAY', 'items' => $string];
        $object = fn ($properties) => ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];

        return [
            'target_profession' => $string, 'candidate_background' => $string,
            'professional_alignment' => ['type' => 'STRING', 'enum' => self::ALIGNMENTS],
            'false_positive_risk' => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high']],
            'core_skills_matched' => $strings, 'transferable_skills_matched' => $strings,
            'general_competencies_matched' => $strings, 'unrelated_skills' => $strings,
            'qualification_relevance' => $object(['status' => $string, 'explanation' => $string]),
            'relevant_experience' => $object([
                'direct_years' => ['type' => 'NUMBER', 'nullable' => true],
                'related_years' => ['type' => 'NUMBER', 'nullable' => true],
                'transferable_years' => ['type' => 'NUMBER', 'nullable' => true],
                'explanation' => $string,
            ]),
            'missing_core_requirements' => $strings, 'cross_industry_warnings' => $strings,
            'score_validation_explanation' => $string,
        ];
    }

    public function score(array $data): array
    {
        $items = $data['requirements'];
        // Historical responses without relevance classifications retain their original method.
        if (! $items || count(array_filter($items, fn ($item) => isset($item['relevance']))) !== count($items)) {
            return $data;
        }
        foreach ($data['requirements'] as &$item) {
            if (trim($item['evidence'] ?? '') === '') {
                $item['original_status'] = $item['revamped_status'] = 'not_evidenced';
            }
            if ($item['importance'] === 'mandatory'
                && (! in_array($item['original_status'], ['matched', 'demonstrated', 'directly_evidenced'], true)
                    || in_array($item['relevance'], ['unrelated', 'transferable'], true))) {
                $exists = array_filter($data['warnings'], fn ($warning) => $warning['category'] === $item['category'] && $warning['requirement'] === $item['requirement']);
                if (! $exists) {
                    $data['warnings'][] = ['severity' => 'high', 'category' => $item['category'], 'requirement' => $item['requirement'],
                        'explanation' => 'This mandatory requirement is not fully evidenced by relevant professional capabilities.',
                        'evidence' => $item['evidence'], 'recommended_action' => 'Confirm relevant evidence or explain the gap; do not add unsupported claims.'];
                }
            }
        }
        unset($item);
        $items = $data['requirements'];
        foreach (CvAiAssessment::WEIGHTS as $category => $maximum) {
            $requirements = array_filter($items, fn ($item) => $item['category'] === $category);
            if (! $requirements) {
                $data['categories'][$category]['applicability'] = 'not_applicable';
                $data['categories'][$category]['original_points'] = $data['categories'][$category]['revamped_points'] = 0;

                continue;
            }
            $data['categories'][$category]['applicability'] = 'applicable';
            foreach (['original', 'revamped'] as $stage) {
                $earned = $possible = 0;
                foreach ($requirements as $item) {
                    $priority = $item['importance'] === 'mandatory' ? 3 : 1;
                    $possible += $priority;
                    $relevance = match ($item['relevance']) {
                        'core' => 1, 'transferable' => 0.5, 'general' => 0.25, default => 0,
                    };
                    $evidence = empty(trim($item['evidence'] ?? '')) ? 0 : match ($item[$stage.'_status']) {
                        'matched', 'demonstrated', 'directly_evidenced' => 1,
                        'partially_matched', 'partially_evidenced', 'transferable_experience' => 0.5,
                        'stated_only' => 0.25, default => 0,
                    };
                    // General competencies have their own bounded category; elsewhere they get partial credit.
                    if ($category === 'competencies' && $item['relevance'] === 'general') {
                        $relevance = 1;
                    }
                    $earned += $priority * $relevance * $evidence;
                }
                $data['categories'][$category][$stage.'_points'] = round($maximum * $earned / $possible, 4);
            }
        }
        $data['scoring_method'] = self::VERSION;

        return $data;
    }
}
