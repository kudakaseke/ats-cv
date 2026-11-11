<?php

namespace Tests\Unit;

use App\Services\CvCompatibilityScore;
use App\Services\CvIndustryRelevance;
use PHPUnit\Framework\TestCase;

class CvCompatibilityScoreTest extends TestCase
{
    public function test_wording_improvement_contributes_without_inventing_candidate_evidence(): void
    {
        $score = new CvCompatibilityScore;
        $this->assertSame(36.0, $score->calculate(45, 0));
        $this->assertSame(38.0, $score->calculate(45, 10));
        $this->assertSame(100.0, $score->calculate(100, 100));
        $this->assertSame(0.0, $score->calculate(0, 0));
        $this->assertSame(37.0, $score->calculate(45, 5));
    }

    public function test_unavailable_or_invalid_inputs_do_not_become_a_score(): void
    {
        $score = new CvCompatibilityScore;
        foreach ([[null, 10], [45, null], [-1, 10], [45, 101], [INF, 10]] as [$evidence, $wording]) {
            $this->assertNull($score->calculate($evidence, $wording));
        }
    }

    public function test_readable_structure_improvement_contributes_to_compatibility(): void
    {
        $score = new CvCompatibilityScore;
        $this->assertSame(45.5, $score->calculate(45, 50, 40));
        $this->assertSame(50.5, $score->calculate(45, 50, 90));

        $assessment = ['original_score' => ['total' => 45], 'revamped_score' => ['total' => 45]];
        $review = $score->review(['assessment' => $assessment, 'keyword_before' => 50, 'keyword_after' => 50,
            'structure_before' => 40, 'structure_after' => 90]);
        $this->assertSame(45.5, $review['compatibility_before']);
        $this->assertSame(50.5, $review['compatibility_after']);
    }

    public function test_scan_review_and_cached_payload_recalculation_use_identical_formula(): void
    {
        $score = new CvCompatibilityScore;
        $assessment = ['scoring_method' => CvIndustryRelevance::VERSION,
            'original_score' => ['total' => 45], 'revamped_score' => ['total' => 45]];
        $scan = $score->scan(['keyword_score' => 0, 'assessment' => $assessment, 'compatibility_score' => 45]);
        $review = $score->review(['keyword_before' => 0, 'keyword_after' => 10, 'assessment' => $assessment]);
        $rewrite = $score->rewrite(['before' => ['keyword_score' => 0], 'after' => ['keyword_score' => 10], 'assessment' => $assessment]);
        $this->assertSame(36.0, $scan['compatibility_score']);
        $this->assertSame($scan['compatibility_score'], $review['compatibility_before']);
        $this->assertSame(38.0, $review['compatibility_after']);
        $this->assertSame($review['compatibility_after'], $rewrite['compatibility_after']);
        $this->assertSame($assessment, $rewrite['assessment']);
        $this->assertSame($scan, $score->scan($scan));
    }

    public function test_relevance_assessment_keeps_evidence_score_when_wording_is_unavailable(): void
    {
        $score = new CvCompatibilityScore;
        $assessment = ['scoring_method' => CvIndustryRelevance::VERSION, 'original_score' => ['total' => 45.25]];

        $this->assertSame(45.25, $score->scan(['assessment' => $assessment, 'keyword_score' => null])['compatibility_score']);
    }
}
