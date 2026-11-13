<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CvAiAssessment;
use App\Services\CvStructuredResume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CvAiAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function assessment(): array
    {
        $data = [
            'job_information' => ['title' => 'Finance Officer', 'industry' => 'Finance', 'seniority' => 'Junior', 'employment_type' => ''],
            'candidate_profile' => array_fill_keys(['qualifications', 'certifications', 'skills', 'competencies', 'experience', 'projects'], []),
            'requirements' => [['category' => 'skills', 'requirement' => 'SQL', 'importance' => 'mandatory', 'evidence' => '', 'original_status' => 'not_evidenced', 'revamped_status' => 'not_evidenced']],
            'experience' => ['required_years' => null, 'documented_relevant_years' => 2, 'total_professional_years' => 2, 'explanation' => 'No minimum years stated.', 'date_warnings' => []],
            'warnings' => [['severity' => 'high', 'category' => 'skills', 'requirement' => 'SQL', 'explanation' => 'SQL is not evidenced.', 'evidence' => '', 'recommended_action' => 'Confirm relevant training.']],
            'verified_information_to_highlight' => ['Monthly reports'], 'information_requiring_confirmation' => ['SQL'],
            'unsupported_job_requirements' => ['SQL'], 'questions_for_candidate' => ['Do you have SQL training?'], 'recommendations' => ['Highlight report writing.'],
        ];
        foreach (['education' => [20, 25], 'certifications' => [0, 0], 'skills' => [10, 20], 'competencies' => [5, 10], 'experience' => [12, 15], 'responsibilities' => [4, 8]] as $name => [$before, $after]) {
            $data['categories'][$name] = ['applicability' => $name === 'certifications' ? 'not_applicable' : 'applicable', 'original_points' => $before, 'revamped_points' => $after, 'explanation' => 'Evidence-based category comparison.'];
        }
        return $data;
    }

    public function test_weights_are_redistributed_and_rewriting_cannot_invent_education_or_experience_points(): void
    {
        $result = app(CvAiAssessment::class)->normalise($this->assessment());
        $this->assertSame(56.67, $result['original_score']['total']);
        $this->assertSame(77.78, $result['revamped_score']['total']);
        $this->assertSame(0, $result['category_weights']['certifications']);
        $this->assertEqualsWithDelta(100, array_sum($result['category_weights']), 0.001);
        $this->assertSame('review_required', $result['mandatory_requirements_status']);
        $this->assertSame(20, $result['categories']['education']['revamped_points']);
        $this->assertSame(12, $result['categories']['experience']['revamped_points']);
        $this->assertNull($result['experience']['required_years']);
    }

    public function test_original_assessment_and_applicability_do_not_drift_on_rewrite(): void
    {
        $baseline = app(CvAiAssessment::class)->normalise($this->assessment());
        $changed = $this->assessment();
        $changed['categories']['skills']['original_points'] = 25;
        $changed['categories']['certifications']['applicability'] = 'applicable';
        $result = app(CvAiAssessment::class)->normalise($changed, $baseline);
        $this->assertSame($baseline['original_score'], $result['original_score']);
    }

    public function test_invalid_points_are_rejected_instead_of_showing_a_fabricated_total(): void
    {
        $data = $this->assessment();
        $data['categories']['skills']['original_points'] = 90;
        $this->expectException(ValidationException::class);
        app(CvAiAssessment::class)->normalise($data);
    }

    public function test_incomplete_openai_response_is_not_retried_and_app_credits_are_refunded(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 300], 'output' => []])]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/checker/rewrite', ['job_description' => 'Skills: PHP', 'resume_text' => "Alex Example\nSkills\nPHP", 'confirmed_keywords' => []])
            ->assertStatus(503)->assertJsonPath('credits_remaining', 15)->assertJsonMissingPath('rewritten_resume');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'status' => 'failed', 'input_tokens' => 120, 'output_tokens' => 300]);
    }

    public function test_semantic_assessment_reaches_scan_and_rewrite_review_without_trusting_browser_scores(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key', 'services.openai.model' => 'gpt-4.1']);
        $structured = [];
        foreach (app(CvStructuredResume::class)->schema()['properties'] as $name => $property) {
            $structured[$name] = $property['type'] === 'STRING' ? '' : [];
        }
        $structured['name'] = 'Alex Example';
        $structured['summary'] = 'Prepared monthly reports for the finance team.';
        $assessment = $this->assessment();
        Http::fake(function ($request) use ($assessment, $structured) {
            $rewrite = isset($request['text']['format']['schema']['properties']['structured_resume']);
            $data = $rewrite ? ['structured_resume' => $structured, 'changes' => ['Highlighted monthly reporting.'], 'unsupported_keywords' => ['SQL'], 'assessment' => $assessment]
                : ['summary' => 'Report writing is evidenced; SQL requires confirmation.', 'recommendations' => [], 'assessment' => $assessment];
            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 50], 'output_tokens' => 200],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]]);
        });
        $input = ['job_description' => "Skills:\nmonthly reporting, SQL", 'resume_text' => "Alex Example\nPrepared monthly reports."];
        $this->actingAs(User::factory()->create())->postJson('/checker/scan', $input)->assertOk()->assertJsonPath('compatibility_score', 49.67)->assertJsonPath('structure_score', 0);
        $this->assertDatabaseHas('ai_usage_logs', ['provider' => 'openai', 'model' => 'gpt-4.1',
            'cached_input_tokens' => 50, 'input_cost_micros' => 125, 'output_cost_micros' => 1600,
            'total_cost_micros' => 1725]);
        $draft = $this->postJson('/checker/rewrite', [...$input, 'confirmed_keywords' => []])->assertOk()->assertJsonPath('original_score.total', 56.67)->assertJsonPath('revamped_score.total', 77.78)->json();
        $this->post('/checker/edit', ['generated' => 1, 'resume_text' => str_replace("\n", "\r\n", $draft['rewritten_resume']),
            'structured_resume' => json_encode($draft['structured_resume']), 'original_resume_text' => str_replace("\n", "\r\n", $input['resume_text']),
            'job_description' => str_replace("\n", "\r\n", $input['job_description']), 'ai_changes' => $draft['changes'], 'score' => 100])->assertRedirect(route('builder.start'))
            ->assertSessionHas('cv_builder.checker_result.assessment.original_score.total', 56.67);
        $this->assertSame('Technical Skills', session('cv_builder.data.section_titles.skills'));
        $this->assertSame('Competencies', session('cv_builder.data.section_titles.core_strengths'));
        $this->get('/get-started')->assertOk()->assertDontSee('Candidate Evidence Score')->assertSee('Warnings');
        Http::assertSent(function ($request) {
            $input = json_decode($request['input'][0]['content'], true);
            return isset($input['current_date'], $input['baseline_assessment']) && $request['text']['format']['strict'] === true;
        });
    }

    public function test_existing_zero_score_review_recovers_its_assessment_without_another_ai_request(): void
    {
        Http::preventStrayRequests();
        $original = "Alex Example\nSkills\nReporting";
        $revised = "Alex Example\nSkills\nMonthly reporting";
        $job = "Skills\nMonthly reporting, SQL";
        $comparison = app(\App\Services\CvReviewComparison::class)->make($job, $original, $revised);
        $comparison['comparison_version'] = 5;
        $comparison['original_text'] = str_replace("\n", "\r\n", $original);
        $comparison['job_description'] = str_replace("\n", "\r\n", $job);
        $assessment = app(CvAiAssessment::class)->normalise($this->assessment());
        $this->actingAs(User::factory()->create())->withSession([
            'cv_builder' => ['step' => 4, 'data' => ['name' => 'Alex Example'], 'checker_result' => $comparison],
            'cv_checker.generated' => ['original_text' => $original, 'job_description' => $job, 'text' => $revised, 'assessment' => $assessment],
        ])->get('/get-started')->assertOk()->assertSee('Revamped 65.88/100')->assertDontSee('Candidate Evidence Score')
            ->assertSessionHas('cv_builder.checker_result.assessment.original_score.total', 56.67);
        Http::assertNothingSent();
    }

    public function test_requirement_details_do_not_display_a_third_score(): void
    {
        $assessment = app(CvAiAssessment::class)->normalise($this->assessment());
        foreach ([false, true] as $revised) {
            $this->blade('<x-ai-assessment :assessment="$assessment" :revised="$revised" />', compact('assessment', 'revised'))
                ->assertSee('Recommendation')->assertSee('Job requirements')->assertSee('CV / Resume')->assertSee('Warnings')
                ->assertSee('Mandatory')->assertDontSee('Candidate Evidence')
                ->assertDontSee('/100')->assertDontSee('@if', false)->assertDontSee('@endif', false);
        }
    }

    public function test_review_headline_compares_original_and_revised_wording_scores(): void
    {
        $comparison = app(\App\Services\CvReviewComparison::class)->make('SQL', 'Alex Example', 'Alex Example');
        $comparison['keyword_before'] = 13;
        $comparison['keyword_after'] = 78;
        $comparison['changes'] = ['Rewrote the professional summary.'];
        $comparison['assessment'] = app(CvAiAssessment::class)->normalise($this->assessment());
        $response = $this->actingAs(User::factory()->create())->withSession(['cv_builder' => [
            'step' => 4, 'data' => ['name' => 'Alex Example'], 'checker_result' => $comparison,
        ]])->get('/get-started')->assertOk()->assertSee('Wording Match')->assertSee('ATS Compatibility')
            ->assertSee('Original 13/100')->assertSee('Revamped 78/100')
            ->assertSee('Original 42.27/100')->assertSee('Revamped 70.05/100')->assertDontSee('@if($revised)', false);
        $html = $response->getContent();
        $orderedHeadings = [
            '<summary>What AI changed</summary>',
            '<summary id="assessment-recommendations-title">Recommendation</summary>',
            '<summary id="assessment-requirements-title">Job requirements</summary>',
            '<summary id="assessment-missing-title">Missing requirements',
            '<summary>Compare original and tailored text</summary>',
            '<summary id="assessment-warnings-title">Warnings</summary>',
        ];
        $positions = array_map(fn ($heading) => strpos($html, $heading), $orderedHeadings);
        $this->assertNotContains(false, $positions);
        for ($index = 1; $index < count($positions); $index++) {
            $this->assertLessThan($positions[$index], $positions[$index - 1]);
        }
    }

    public function test_missing_compatibility_is_shown_as_unavailable_instead_of_zero(): void
    {
        $this->blade('<x-score-pair :wording="7" />')->assertSee('Wording Match')->assertSee('ATS Compatibility')
            ->assertSee('Not available for this check')->assertDontSee('Original 0/100');
    }

    public function test_review_shows_wording_contribution_when_candidate_evidence_is_unchanged(): void
    {
        $comparison = app(\App\Services\CvReviewComparison::class)->make('SQL', 'Alex Example', 'Alex Example');
        $comparison['keyword_before'] = 0;
        $comparison['keyword_after'] = 10;
        $assessment = app(CvAiAssessment::class)->normalise($this->assessment());
        $assessment['original_score']['total'] = 45;
        $assessment['revamped_score']['total'] = 45;
        $comparison['assessment'] = $assessment;
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => [
            'step' => 4, 'data' => ['name' => 'Alex Example'], 'checker_result' => $comparison,
        ]])->get('/get-started')->assertOk()->assertSee('Original 31.5/100')->assertSee('Revamped 33.5/100')
            ->assertSee('contributing 2 points to the ATS Compatibility change')
            ->assertDontSee('Candidate Evidence Score')->assertDontSee('45/100');
        Http::assertNothingSent();
    }

    public function test_recovery_does_not_attach_assessment_to_a_different_advert(): void
    {
        $original = "Alex Example\nSkills\nReporting";
        $comparison = app(\App\Services\CvReviewComparison::class)->make('SQL', $original, $original);
        $this->actingAs(User::factory()->create())->withSession([
            'cv_builder' => ['step' => 4, 'data' => ['name' => 'Alex Example'], 'checker_result' => $comparison],
            'cv_checker.generated' => ['original_text' => $original, 'job_description' => 'Java', 'text' => $original, 'assessment' => app(CvAiAssessment::class)->normalise($this->assessment())],
        ])->get('/get-started')->assertOk()->assertSessionMissing('cv_builder.checker_result.assessment');
    }

    public function test_supported_terms_reach_skills_and_strengths_without_adding_partial_or_invented_requirements(): void
    {
        $requirements = [
            ['category' => 'skills', 'requirement' => 'Monthly reporting', 'original_status' => 'matched', 'evidence' => 'Prepared monthly reports.'],
            ['category' => 'competencies', 'requirement' => 'Team player', 'original_status' => 'demonstrated', 'evidence' => 'Worked with colleagues.'],
            ['category' => 'skills', 'requirement' => 'Audit controls', 'original_status' => 'partially_matched', 'evidence' => 'Prepared monthly reports.'],
            ['category' => 'skills', 'requirement' => 'SQL', 'original_status' => 'matched', 'evidence' => 'Built SQL databases.'],
        ];
        $result = app(\App\Services\CvSupportedTerms::class)->apply(['skills' => ['Excel'], 'core_strengths' => []], ['requirements' => $requirements],
            "Alex Example\nPrepared monthly reports.\nWorked with colleagues.");
        $this->assertSame(['Monthly reporting', 'Excel'], $result['skills']);
        $this->assertSame(['Team player'], $result['core_strengths']);
    }

    public function test_review_repairs_missing_supported_terms_but_preserves_manual_edits(): void
    {
        $mapper = app(CvStructuredResume::class);
        $structured = [];
        foreach ($mapper->schema()['properties'] as $name => $property) {
            $structured[$name] = $property['type'] === 'STRING' ? '' : [];
        }
        $structured['name'] = 'Alex Example';
        $structured['summary'] = 'Prepared monthly reports.';
        $text = $mapper->text($mapper->data($structured));
        $source = "Alex Example\nPrepared monthly reports.\nWorked with colleagues.";
        $job = "Skills\nMonthly reporting\nPersonal Attributes\nTeam player";
        $raw = $this->assessment();
        $raw['requirements'] = [
            ['category' => 'skills', 'requirement' => 'Monthly reporting', 'importance' => 'mandatory', 'original_status' => 'matched', 'revamped_status' => 'matched', 'evidence' => 'Prepared monthly reports.'],
            ['category' => 'competencies', 'requirement' => 'Team player', 'importance' => 'mandatory', 'original_status' => 'demonstrated', 'revamped_status' => 'demonstrated', 'evidence' => 'Worked with colleagues.'],
        ];
        $generated = ['text' => $text, 'structured_resume' => $structured, 'original_text' => $source, 'job_description' => $job, 'assessment' => app(CvAiAssessment::class)->normalise($raw)];
        $draft = ['data' => $mapper->data($structured), 'checker_result' => app(\App\Services\CvReviewComparison::class)->make($job, $source, $text)];
        [$repaired, $updated] = app(\App\Services\CvRevampReviewRepair::class)->repair($draft, $generated);
        $this->assertSame('Monthly reporting', $repaired['data']['skills']);
        $this->assertSame('Team player', $repaired['data']['core_strengths']);
        $this->assertSame(['skills' => 'Skills', 'core_strengths' => 'Competencies'], $repaired['data']['section_titles']);
        $this->assertSame($generated['assessment']['original_score'], $repaired['checker_result']['assessment']['original_score']);
        $this->assertSame($updated['text'], $repaired['checker_result']['revised_text']);
        $draft['data']['summary'] = 'My manually edited summary.';
        [$unchanged] = app(\App\Services\CvRevampReviewRepair::class)->repair($draft, $generated);
        $this->assertSame($draft, $unchanged);
    }
}
