<?php

namespace App\Services;

class CvCompatibilityScore
{
    public const VERSION = 'evidence-wording-text-structure-v5';

    private function valid($value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 100;
    }

    private function assessmentScore(array $assessment, string $stage, $wording, $structure = null): ?float
    {
        $evidence = $assessment[$stage]['total'] ?? null;
        if (!$this->valid($evidence) || !$this->valid($wording) || ($structure !== null && !$this->valid($structure))) return null;
        if (($assessment['scoring_method'] ?? '') === CvIndustryRelevance::VERSION) {
            // Presentation can improve clarity, but cannot replace absent role evidence.
            $presentation = $structure === null ? 0.2 * $wording / 100 : (0.15 * $wording + 0.05 * $structure) / 100;
            return round($evidence * (0.8 + $presentation), 2);
        }
        return $this->calculate($evidence, $wording, $structure);
    }

    public function calculate($evidence, $wording, $structure = null): ?float
    {
        if (!$this->valid($evidence) || !$this->valid($wording) || ($structure !== null && !$this->valid($structure))) return null;
        return round($evidence * 0.8 + ($structure === null ? $wording * 0.2 : $wording * 0.15 + $structure * 0.05), 2);
    }

    private function structure(string $text): array
    {
        $checks = app(CvCompatibilityChecker::class)->check($text);
        return ['structure_score' => round(100 * $checks['passed'] / $checks['total'], 2), 'structure_checks' => $checks['checks']];
    }

    private function assessment(array $assessment): array
    {
        return isset($assessment['experience']) ? app(CvExperienceMatcher::class)->normaliseAssessment($assessment) : $assessment;
    }

    public function scan(array $result, ?string $source = null): array
    {
        $assessment = $this->assessment($result['assessment'] ?? $result['ai_analysis']['assessment'] ?? []);
        if (isset($result['assessment'])) $result['assessment'] = $assessment;
        if (isset($result['ai_analysis']['assessment'])) $result['ai_analysis']['assessment'] = $assessment;
        if ($source !== null) $result = [...$result, ...$this->structure($source)];
        $result['compatibility_score'] = $this->assessmentScore($assessment, 'original_score', $result['keyword_score'] ?? null, $result['structure_score'] ?? null);
        $result['compatibility_method'] = self::VERSION;
        return $result;
    }

    public function review(array $comparison): array
    {
        $assessment = $this->assessment($comparison['assessment'] ?? []);
        if (isset($comparison['assessment'])) $comparison['assessment'] = $assessment;
        foreach (['before' => 'original_text', 'after' => 'revised_text'] as $stage => $field) {
            if (isset($comparison[$field])) $comparison['structure_'.$stage] = $this->structure($comparison[$field])['structure_score'];
        }
        $before = $comparison['structure_before'] ?? null;
        $after = $comparison['structure_after'] ?? null;
        $comparison['compatibility_before'] = $this->assessmentScore($assessment, 'original_score', $comparison['keyword_before'] ?? null, $before);
        $comparison['compatibility_after'] = $this->assessmentScore($assessment, 'revamped_score', $comparison['keyword_after'] ?? null, $after);
        $newWording = $this->assessmentScore($assessment, 'original_score', $comparison['keyword_after'] ?? null, $before);
        $comparison['wording_effect'] = $newWording !== null && $comparison['compatibility_before'] !== null ? round($newWording - $comparison['compatibility_before'], 2) : null;
        $comparison['compatibility_method'] = self::VERSION;
        return $comparison;
    }

    public function rewrite(array $payload, ?string $source = null): array
    {
        if ($source !== null) {
            $payload['before'] = [...$payload['before'], ...$this->structure($source)];
            $payload['after'] = [...$payload['after'], ...$this->structure($payload['rewritten_resume'])];
        }
        $scores = $this->review(['assessment' => $payload['assessment'] ?? [],
            'keyword_before' => $payload['before']['keyword_score'] ?? null, 'keyword_after' => $payload['after']['keyword_score'] ?? null,
            'structure_before' => $payload['before']['structure_score'] ?? null, 'structure_after' => $payload['after']['structure_score'] ?? null]);
        if (isset($payload['assessment'])) $payload['assessment'] = $scores['assessment'];
        return [...$payload, ...array_intersect_key($scores, array_flip(['compatibility_before', 'compatibility_after', 'compatibility_method', 'structure_before', 'structure_after', 'wording_effect']))];
    }
}
