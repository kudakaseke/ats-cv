<?php

namespace Tests\Unit;

use App\Services\CvCompatibilityScore;
use PHPUnit\Framework\TestCase;

class CvCompatibilityScoreTest extends TestCase
{
    public function test_relevance_scoring_rewards_wording_without_overriding_role_evidence(): void
    {
        $score = new CvCompatibilityScore;
        foreach ([0, 16, 60, 100] as $evidence) {
            $assessment = ['scoring_method' => \App\Services\CvIndustryRelevance::VERSION,
                'original_score' => ['total' => $evidence], 'revamped_score' => ['total' => $evidence]];
            $review = $score->review(['assessment' => $assessment, 'keyword_before' => 20, 'keyword_after' => 80]);
            $this->assertLessThanOrEqual($evidence, $review['compatibility_after']);
            if ($evidence > 0) $this->assertGreaterThan($review['compatibility_before'], $review['compatibility_after']);
            else $this->assertSame(0.0, $review['compatibility_after']);
            $rewrite = $score->rewrite(['assessment' => $assessment, 'before' => ['keyword_score' => 20], 'after' => ['keyword_score' => 80]]);
            $this->assertSame($review['compatibility_after'], $rewrite['compatibility_after']);
            $this->assertSame($review['compatibility_before'], $score->scan(['assessment' => $assessment, 'keyword_score' => 20])['compatibility_score']);
        }
    }

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

    public function test_scan_review_and_cached_payload_recalculation_use_identical_formula(): void
    {
        $score = new CvCompatibilityScore;
        $assessment = ['original_score' => ['total' => 45], 'revamped_score' => ['total' => 45]];
        $scan = $score->scan(['keyword_score' => 0, 'assessment' => $assessment, 'compatibility_score' => 45]);
        $review = $score->review(['keyword_before' => 0, 'keyword_after' => 10, 'assessment' => $assessment]);
        $rewrite = $score->rewrite(['before' => ['keyword_score' => 0], 'after' => ['keyword_score' => 10], 'assessment' => $assessment]);
        $this->assertSame($scan['compatibility_score'], $review['compatibility_before']);
        $this->assertSame(38.0, $review['compatibility_after']);
        $this->assertSame($review['compatibility_after'], $rewrite['compatibility_after']);
        $this->assertSame($assessment, $rewrite['assessment']);
        $this->assertSame($scan, $score->scan($scan));
    }
}
