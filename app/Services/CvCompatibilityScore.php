<?php

namespace App\Services;

class CvCompatibilityScore
{
    public const VERSION = 'relevance-aware-compatibility-v6';

    private const EVIDENCE_WEIGHT = 0.7;
    private const WORDING_WEIGHT = 0.2;
    private const STRUCTURE_WEIGHT = 0.1;

    private function assessmentScore(array $assessment, string $stage, $wording, $structure = null): ?float
    {
        $evidence = $assessment[$stage]['total'] ?? null;
        if (($assessment['scoring_method'] ?? '') === CvIndustryRelevance::VERSION && ! is_numeric($wording)) {
            return is_numeric($evidence) && is_finite((float) $evidence) && $evidence >= 0 && $evidence <= 100
                ? round((float) $evidence, 2)
                : null;
        }

        return $this->calculate($evidence, $wording, $structure);
    }

    public function calculate($evidence, $wording, $structure = null): ?float
    {
        foreach ([$evidence, $wording] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
                return null;
            }
        }
        if ($structure === null) {
            return round((float) $evidence * 0.8 + (float) $wording * 0.2, 2);
        }
        if (! is_numeric($structure) || ! is_finite((float) $structure) || $structure < 0 || $structure > 100) {
            return null;
        }

        return round((float) $evidence * self::EVIDENCE_WEIGHT
            + (float) $wording * self::WORDING_WEIGHT
            + (float) $structure * self::STRUCTURE_WEIGHT, 2);
    }

    public function structureScore(string $resume): float
    {
        $check = (new CvCompatibilityChecker)->check($resume);

        return round($check['passed'] / $check['total'] * 100, 2);
    }

    public function scan(array $result): array
    {
        $assessment = $result['assessment'] ?? $result['ai_analysis']['assessment'] ?? [];
        $result['compatibility_score'] = $this->assessmentScore($assessment, 'original_score', $result['keyword_score'] ?? null, $result['structure_score'] ?? null);
        $result['compatibility_method'] = self::VERSION;
        return $result;
    }

    public function review(array $comparison): array
    {
        $comparison['compatibility_before'] = $this->assessmentScore($comparison['assessment'] ?? [], 'original_score', $comparison['keyword_before'] ?? null, $comparison['structure_before'] ?? null);
        $comparison['compatibility_after'] = $this->assessmentScore($comparison['assessment'] ?? [], 'revamped_score', $comparison['keyword_after'] ?? null, $comparison['structure_after'] ?? null);
        $comparison['compatibility_method'] = self::VERSION;
        return $comparison;
    }

    public function rewrite(array $payload): array
    {
        $scores = $this->review(['assessment' => $payload['assessment'] ?? [],
            'keyword_before' => $payload['before']['keyword_score'] ?? null,
            'keyword_after' => $payload['after']['keyword_score'] ?? null,
            'structure_before' => $payload['structure_before'] ?? null,
            'structure_after' => $payload['structure_after'] ?? null]);
        return [...$payload, ...array_intersect_key($scores, array_flip(['compatibility_before', 'compatibility_after', 'compatibility_method']))];
    }
}
