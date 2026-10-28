<?php

namespace App\Services;

class CvCompatibilityScore
{
    public const VERSION = 'relevance-aware-compatibility-v5';

    private function assessmentScore(array $assessment, string $stage, $wording): ?float
    {
        $evidence = $assessment[$stage]['total'] ?? null;
        if (($assessment['scoring_method'] ?? '') === CvIndustryRelevance::VERSION) {
            foreach ([$evidence, $wording] as $value) {
                if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 100) return null;
            }
            // Wording can improve presentation, but never create missing professional evidence.
            return round((float) $evidence * (0.8 + 0.2 * (float) $wording / 100), 2);
        }

        return $this->calculate($evidence, $wording);
    }

    public function calculate($evidence, $wording): ?float
    {
        foreach ([$evidence, $wording] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
                return null;
            }
        }
        return round((float) $evidence * 0.8 + (float) $wording * 0.2, 2);
    }

    public function scan(array $result): array
    {
        $assessment = $result['assessment'] ?? $result['ai_analysis']['assessment'] ?? [];
        $result['compatibility_score'] = $this->assessmentScore($assessment, 'original_score', $result['keyword_score'] ?? null);
        $result['compatibility_method'] = self::VERSION;
        return $result;
    }

    public function review(array $comparison): array
    {
        $comparison['compatibility_before'] = $this->assessmentScore($comparison['assessment'] ?? [], 'original_score', $comparison['keyword_before'] ?? null);
        $comparison['compatibility_after'] = $this->assessmentScore($comparison['assessment'] ?? [], 'revamped_score', $comparison['keyword_after'] ?? null);
        $comparison['compatibility_method'] = self::VERSION;
        return $comparison;
    }

    public function rewrite(array $payload): array
    {
        $scores = $this->review(['assessment' => $payload['assessment'] ?? [],
            'keyword_before' => $payload['before']['keyword_score'] ?? null,
            'keyword_after' => $payload['after']['keyword_score'] ?? null]);
        return [...$payload, ...array_intersect_key($scores, array_flip(['compatibility_before', 'compatibility_after', 'compatibility_method']))];
    }
}
