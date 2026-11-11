<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CvAiAssessment;
use App\Services\CvCompatibilityScore;
use App\Services\CvDocumentIdentity;
use App\Services\CvIndustryRelevance;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IndustryRelevanceTest extends TestCase
{
    use RefreshDatabase;

    private function assessment(): array
    {
        $data = [
            'job_information' => ['title' => 'Procurement officer', 'industry' => 'Supply chain', 'seniority' => 'Officer', 'employment_type' => 'Permanent'],
            'candidate_profile' => array_fill_keys(['qualifications', 'certifications', 'skills', 'competencies', 'experience', 'projects'], []),
            'experience' => ['required_years' => 2, 'documented_relevant_years' => 0, 'total_professional_years' => 5, 'explanation' => 'IT duties do not establish procurement experience.', 'date_warnings' => []],
            'warnings' => [], 'verified_information_to_highlight' => [], 'information_requiring_confirmation' => [],
            'unsupported_job_requirements' => [], 'questions_for_candidate' => [], 'recommendations' => [],
            'target_profession' => 'Procurement officer', 'candidate_background' => 'IT support',
            'professional_alignment' => 'LIMITED_ALIGNMENT', 'false_positive_risk' => 'high',
            'core_skills_matched' => [], 'transferable_skills_matched' => [], 'general_competencies_matched' => ['Communication', 'Excel'],
            'unrelated_skills' => ['Network configuration'], 'missing_core_requirements' => ['Sourcing', 'Tendering'],
            'cross_industry_warnings' => ['General IT skills do not establish procurement expertise.'],
            'score_validation_explanation' => 'General competencies cannot replace core procurement duties.',
            'qualification_relevance' => ['status' => 'unrelated', 'explanation' => 'IT qualification does not meet the requested procurement field.'],
            'relevant_experience' => ['direct_years' => 0, 'related_years' => 0, 'transferable_years' => 5, 'explanation' => 'No directly relevant duties evidenced.'],
        ];
        foreach (CvAiAssessment::WEIGHTS as $category => $maximum) {
            // Deliberately inflated model points: application must replace these.
            $data['categories'][$category] = ['applicability' => 'applicable', 'original_points' => $maximum, 'revamped_points' => $maximum, 'explanation' => 'Provider estimate.'];
            $data['requirements'][] = ['category' => $category, 'requirement' => $category.' procurement requirement',
                'importance' => 'mandatory', 'relevance' => $category === 'competencies' ? 'general' : 'core',
                'evidence' => $category === 'competencies' ? 'Communicated with users.' : '',
                'original_status' => $category === 'competencies' ? 'demonstrated' : 'not_evidenced',
                'revamped_status' => $category === 'competencies' ? 'demonstrated' : 'not_evidenced'];
        }
        $data['requirements'][] = ['category' => 'skills', 'requirement' => 'Excel', 'importance' => 'mandatory', 'relevance' => 'general',
            'evidence' => 'Excel reports', 'original_status' => 'matched', 'revamped_status' => 'matched'];

        return $data;
    }

    public function test_generic_overlap_cannot_produce_high_procurement_match_for_it_cv(): void
    {
        $result = app(CvAiAssessment::class)->normalise($this->assessment());
        $this->assertSame(CvIndustryRelevance::VERSION, $result['scoring_method']);
        $this->assertLessThan(25, $result['original_score']['total']);
        $this->assertSame('review_required', $result['mandatory_requirements_status']);
        $scan = app(CvCompatibilityScore::class)->scan(['assessment' => $result, 'keyword_score' => 100]);
        $this->assertSame(app(CvCompatibilityScore::class)->calculate($result['original_score']['total'], 100), $scan['compatibility_score']);
        $this->assertIsFloat($scan['compatibility_score']);
        $this->view('components.ai-assessment', ['assessment' => $result])->assertSee('Limited alignment')->assertSee('Tendering');
    }

    public function test_industry_label_alone_never_caps_genuine_role_evidence(): void
    {
        $data = $this->assessment();
        $data['professional_alignment'] = 'TRANSFERABLE_BACKGROUND';
        foreach ($data['requirements'] as &$requirement) {
            $requirement['relevance'] = 'core';
            $requirement['evidence'] = 'Documented relevant work.';
            $requirement['original_status'] = $requirement['revamped_status'] = 'matched';
        }
        unset($requirement);
        $result = app(CvAiAssessment::class)->normalise($data);
        $this->assertEquals(100, $result['original_score']['total']);
    }

    public function test_rewrite_cannot_drop_core_gaps_or_promote_generic_skills(): void
    {
        $data = $this->assessment();
        $baseline = app(CvAiAssessment::class)->normalise($data);
        $data['requirements'] = [array_replace(end($data['requirements']), ['relevance' => 'core'])];
        $result = app(CvAiAssessment::class)->normalise($data, $baseline);
        $this->assertSame($baseline['original_score'], $result['original_score']);
        $this->assertSame($baseline['revamped_score'], $result['revamped_score']);
        $this->assertSame($baseline['missing_core_requirements'], $result['missing_core_requirements']);
    }

    public function test_industry_selection_is_absent_and_unrecognised_values_are_rejected(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create())->get('/workspace/checker')->assertOk()->assertDontSee('Choose Industry')->assertDontSee('checker-industry')->assertSee('Paste the job description');
        $this->postJson('/checker/scan', ['industry' => 'forged', 'job_description' => 'Skills: Excel', 'resume_text' => 'Excel'])->assertUnprocessable()->assertJsonValidationErrors('industry');
        Http::assertNothingSent();
        $this->assertNotSame(CvDocumentIdentity::signature('job', 'cv', 'it'), CvDocumentIdentity::signature('job', 'cv', 'supply-chain'));
    }

    public function test_selected_industry_reaches_provider_and_score_remains_a_percentage(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        config(['services.openai.key' => 'test-key', 'services.openai.model' => 'test-model']);
        $data = ['summary' => 'Core procurement experience is not evidenced.', 'recommendations' => [], 'assessment' => $this->assessment()];
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]],
        ]])]);
        $result = $this->actingAs(User::factory()->create())->postJson('/checker/scan', [
            'industry' => 'supply-chain', 'job_description' => 'Skills: Excel, Sourcing', 'resume_text' => 'Excel reports. Communicated with users.',
        ])->assertOk()->assertJsonPath('selected_industry', 'supply-chain')->assertJsonPath('assessment.professional_alignment', 'LIMITED_ALIGNMENT')->json();
        $this->assertIsNumeric($result['compatibility_score']);
        $this->assertLessThan(25, $result['compatibility_score']);
        Http::assertSent(function ($request) {
            $input = json_decode($request['input'][0]['content'], true);

            return $input['selected_industry'] === config('industries.supply-chain')
                && isset($request['text']['format']['schema']['properties']['assessment']['properties']['professional_alignment']);
        });
    }
}
