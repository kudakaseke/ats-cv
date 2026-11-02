<?php
namespace Tests\Feature;

use App\Services\CvCompatibilityScore;
use App\Services\CvIndustryRelevance;
use Tests\TestCase;

class CvPresentationScoreTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    public function test_scan_rewrite_review_and_cached_scores_agree_and_keep_qualification_gaps(): void
    {
        $score = app(CvCompatibilityScore::class);
        $original = "Alex Example\nSkills\nPHP";
        $rewritten = "Alex Example\nalex@example.invalid\n+263 700 000 000\nProfessional Summary\n".str_repeat('Supported application development and user support. ', 20)
            ."\nProfessional Experience\nDeveloper\n2020 - Present\nEducation\nDiploma\nSkills\nPHP, SQL";
        $assessment = ['scoring_method' => CvIndustryRelevance::VERSION, 'original_score' => ['total' => 60], 'revamped_score' => ['total' => 60],
            'warnings' => [['category' => 'certifications', 'requirement' => 'Professional registration', 'explanation' => 'Not evidenced.']]];
        $scan = $score->scan(['assessment' => $assessment, 'keyword_score' => 20], $original);
        $rewrite = $score->rewrite(['assessment' => $assessment, 'rewritten_resume' => $rewritten,
            'before' => ['keyword_score' => 20], 'after' => ['keyword_score' => 80]], $original);
        $review = $score->review(['assessment' => $assessment, 'original_text' => $original, 'revised_text' => $rewritten,
            'keyword_before' => 20, 'keyword_after' => 80]);
        $this->assertSame($scan['compatibility_score'], $rewrite['compatibility_before']);
        $this->assertSame($rewrite['compatibility_before'], $review['compatibility_before']);
        $this->assertSame($rewrite['compatibility_after'], $review['compatibility_after']);
        $this->assertGreaterThan($rewrite['compatibility_before'], $rewrite['compatibility_after']);
        $this->assertGreaterThan($rewrite['structure_before'], $rewrite['structure_after']);
        $this->assertSame(100.0, $rewrite['structure_after']);
        $this->assertSame($assessment, $rewrite['assessment']);
        $this->assertSame($scan['compatibility_score'], $score->scan($scan)['compatibility_score']);
    }
    public function test_excess_relevant_experience_is_green_on_scan_and_saved_result(): void
    {
        $this->mock(\App\Services\CvAiRewriter::class, function ($mock) {
            $mock->shouldReceive('analyse')->once()->andReturn(['summary' => 'Relevant experience exceeds the minimum.', 'recommendations' => [],
                'assessment' => ['scoring_method' => CvIndustryRelevance::VERSION, 'original_score' => ['total' => 75], 'revamped_score' => ['total' => 75],
                    'experience' => ['required_years' => 5, 'documented_relevant_years' => 12, 'total_professional_years' => 14, 'date_warnings' => []],
                    'requirements' => [], 'mandatory_requirements_status' => 'review_required', 'questions_for_candidate' => [],
                    'warnings' => [
                        ['category' => 'experience', 'severity' => 'medium', 'requirement' => '5 years experience', 'explanation' => 'Candidate is overqualified.', 'recommended_action' => 'Review the additional experience.'],
                        ['category' => 'certifications', 'severity' => 'high', 'requirement' => 'Professional licence', 'explanation' => 'Not evidenced.', 'recommended_action' => 'Confirm if held.'],
                    ]]]);
        });
        $user = \App\Models\User::factory()->create();
        $response = $this->actingAs($user)->postJson('/checker/scan', ['job_description' => 'Minimum 5 years of experience. Skills: PHP',
            'resume_text' => "Alex Example\nExperience\nDeveloper\nJanuary 2012 - December 2025\nSkills\nPHP"])->assertOk()
            ->assertJsonPath('experience_check.status', 'exceeds_minimum')->assertJsonPath('experience_check.positive', true)
            ->assertJsonPath('experience_check.shortfall', false)->assertJsonCount(1, 'assessment.warnings')
            ->assertJsonPath('assessment.warnings.0.category', 'certifications');
        $analysis = $user->analyses()->findOrFail($response->json('analysis_id'));
        $this->get(route('workspace.analysis', $analysis))->assertOk()->assertSee('Experience strength')
            ->assertSee('Professional licence')->assertDontSee('Candidate is overqualified.');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
