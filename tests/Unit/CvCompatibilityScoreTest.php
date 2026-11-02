<?php

namespace Tests\Unit;

use App\Services\CvCompatibilityScore;
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
    public function test_current_relevance_scores_increase_with_wording_without_changing_facts(): void
    {
        $score = new CvCompatibilityScore;
        $assessment = ['scoring_method' => \App\Services\CvIndustryRelevance::VERSION,
            'original_score' => ['total' => 40], 'revamped_score' => ['total' => 40]];
        $result = $score->review(['assessment' => $assessment, 'keyword_before' => 20, 'keyword_after' => 80,
            'structure_before' => 50, 'structure_after' => 50]);
        $this->assertSame(34.2, $result['compatibility_before']);
        $this->assertSame(37.8, $result['compatibility_after']);
        $this->assertSame($assessment, $result['assessment']);
        $this->assertSame(3.6, $result['wording_effect']);
        $this->assertSame($result, $score->review($result));
    }

    public function test_structure_improves_presentation_but_cannot_create_professional_evidence(): void
    {
        $score = new CvCompatibilityScore;
        $assessment = ['scoring_method' => \App\Services\CvIndustryRelevance::VERSION,
            'original_score' => ['total' => 60], 'revamped_score' => ['total' => 60]];
        $result = $score->review(['assessment' => $assessment, 'keyword_before' => 50, 'keyword_after' => 50,
            'structure_before' => 0, 'structure_after' => 100]);
        $this->assertSame(52.5, $result['compatibility_before']);
        $this->assertSame(55.5, $result['compatibility_after']);
        $this->assertSame(0.0, $result['wording_effect']);
        $assessment['original_score']['total'] = $assessment['revamped_score']['total'] = 0;
        $zero = $score->review(['assessment' => $assessment, 'keyword_before' => 0, 'keyword_after' => 100,
            'structure_before' => 0, 'structure_after' => 100]);
        $this->assertSame(0.0, $zero['compatibility_after']);
    }
}
