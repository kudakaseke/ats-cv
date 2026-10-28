<?php

namespace Tests\Feature;

use App\Services\CvAiAssessment;
use App\Services\CvAiPrompt;
use App\Services\CvAiRewriter;
use App\Services\CvAiV2Report;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvMasterPromptV2Test extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    private string $source = "Alex Example\nPHP Developer\nSummary\nBuilds PHP applications.\nSkills\nPHP\nEducation\nDiploma in Computing";

    private function assessment(): array
    {
        return \Tests\Fixtures\MasterCvV2::assessment();
    }

    private function draft(): array
    {
        return \Tests\Fixtures\MasterCvV2::draft();
    }

    private function validation(): array
    {
        return \Tests\Fixtures\MasterCvV2::validation();
    }

    private function response(array $data, int $input = 100, int $output = 50): array
    {
        return \Tests\Fixtures\MasterCvV2::response($data, $input, $output);
    }

    private function configure(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key', 'services.openai.model' => 'gpt-6.1-sol', 'services.openai.validate_revamp' => true]);
        Http::preventStrayRequests();
    }

    public function test_master_policy_is_shared_across_all_three_actions(): void
    {
        $prompt = app(CvAiPrompt::class);
        foreach ([$prompt->instructions(false), $prompt->instructions(true), $prompt->validationInstructions()] as $text) {
            $this->assertStringContainsString('VERSION 2.0', $text);
            $this->assertStringContainsString('WHAT AI CHANGED', $text);
            $this->assertStringContainsString('Never guarantee an interview', $text);
        }
        $this->assertStringContainsString('VALIDATE_REVAMP', $prompt->validationInstructions());
    }

    public function test_runtime_policy_does_not_resend_the_full_master_and_duplicate_legacy_policies(): void
    {
        $prompt = app(CvAiPrompt::class)->instructions(true);
        $this->assertLessThan(16000, strlen($prompt));
        $this->assertStringContainsString('Genuine qualification, certification, licence, skill and experience gaps remain visible', $prompt);
        $this->assertStringContainsString('exact before/after excerpts', $prompt);
        $this->assertStringContainsString('Never guarantee an interview', $prompt);
        $this->assertFileExists(resource_path('prompts/atsdetector-master-v2.txt'));
    }

    public function test_direct_sol_rewrite_returns_v2_audit_in_one_request_without_a_prior_scan(): void
    {
        $this->configure();
        config(['services.openai.validate_revamp' => false]);
        Http::fake(['api.openai.com/*' => Http::response($this->response([...$this->draft(), ...$this->validation()]))]);
        $result = (new CvAiRewriter)->rewrite('PHP and mandatory AWS certification', $this->source, []);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-6.1-sol'
            && $request['reasoning']['effort'] === 'low'
            && isset($request['text']['format']['schema']['properties']['ai_changes']));
        $this->assertNotEmpty($result['ai_changes']);
        $this->assertNotEmpty($result['remaining_warnings']);
        $this->assertSame(100.0, $result['keyword_coverage_percentage']);
        $this->assertFalse($result['post_revamp_validation']['independent_model_review']);
        $this->assertStringNotContainsString('AWS', $result['rewritten_resume']);
    }

    public function test_default_sol_scan_returns_immediately_without_queue_and_replays_without_spending(): void
    {
        $this->configure();
        config(['services.openai.validate_revamp' => false]);
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessCvAi::class]);
        Http::fake(['api.openai.com/*' => Http::response($this->response([
            'summary' => 'Review.', 'recommendations' => [], 'assessment' => $this->assessment()]))]);
        $user = \App\Models\User::factory()->create();
        $input = ['job_description' => 'PHP and mandatory AWS certification', 'resume_text' => $this->source,
            'operation_id' => (string) \Illuminate\Support\Str::uuid()];
        $this->actingAs($user)->postJson('/checker/scan', $input)->assertOk();
        $this->actingAs($user)->postJson('/checker/scan', $input)->assertOk();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    public function test_direct_sol_rewrite_carries_the_report_to_the_builder_without_queue(): void
    {
        $this->configure();
        config(['services.openai.validate_revamp' => false]);
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessCvAi::class]);
        Http::fake(['api.openai.com/*' => Http::response($this->response([...$this->draft(), ...$this->validation()]))]);
        $user = \App\Models\User::factory()->create();
        $result = $this->actingAs($user)->postJson('/checker/rewrite', [
            'job_description' => 'PHP and mandatory AWS certification', 'resume_text' => $this->source,
            'confirmed_keywords' => [],
        ])->assertOk()->assertJsonPath('post_revamp_validation.stage', 'SOURCE_AND_COVERAGE_CHECK')
            ->assertSessionHas('cv_checker.generated.report.ai_changes');
        $draft = $result->json();
        $this->post('/checker/edit', ['resume_text' => $draft['rewritten_resume'], 'original_resume_text' => $this->source,
            'job_description' => 'PHP and mandatory AWS certification', 'generated' => 1,
            'structured_resume' => json_encode($draft['structured_resume'])])->assertRedirect(route('builder.start'));
        $this->get(route('builder.start'))->assertOk()->assertSee('What AI changed')->assertSee('AWS certification');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    public function test_direct_sol_connection_failure_refunds_without_an_automatic_retry(): void
    {
        $this->configure();
        config(['services.openai.validate_revamp' => false]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out.'));
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user)->postJson('/checker/scan', ['job_description' => 'PHP and mandatory AWS certification',
            'resume_text' => $this->source])->assertStatus(503);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'status' => 'failed', 'credits_charged' => 0]);
        $this->assertDatabaseHas('ai_operations', ['user_id' => $user->id, 'status' => 'failed']);
    }

    public function test_source_evidence_requires_whole_words_and_never_uses_job_text_as_cv_evidence(): void
    {
        $report = app(CvAiV2Report::class);
        $this->assertFalse($report->evidenceExists('C', 'Accountant'));
        $this->assertFalse($report->evidenceExists('AWS', 'Compliance with laws'));
        $this->assertTrue($report->evidenceExists('Microsoft Excel', 'Used Microsoft Excel for reporting.'));
        $this->assertTrue($report->evidenceExists('PHP', 'Unknown', ['PHP']));
    }

    public function test_laravel_calculates_overlapping_experience_and_caps_relevance_to_source_duration(): void
    {
        $assessment = $this->assessment();
        $assessment['experience']['total_professional_years'] = 99;
        $assessment['experience']['documented_relevant_years'] = 99;
        $source = "Alex Example\nWork Experience\nPHP Developer | Example A | January 2022 - December 2023\nBuilt PHP applications.\nPHP Developer | Example B | January 2023 - December 2023\nBuilt PHP applications.\nEducation\nDiploma in Computing";
        $guarded = app(CvAiV2Report::class)->sourceGuard($assessment, $source);
        $this->assertEquals(2, $guarded['experience']['total_professional_years']);
        $this->assertEquals(2, $guarded['experience']['documented_relevant_years']);
        $this->assertSame('employment_dates', $guarded['experience']['calculation_source']);
    }

    public function test_an_explicitly_confirmed_gap_is_distinct_from_missing_cv_evidence(): void
    {
        $data = $this->assessment();
        $data['requirements'][1]['match_classification'] = 'CONFIRMED_GAP';
        $data['requirements'][1]['evidence'] = 'I do not hold AWS certification';
        $guarded = app(CvAiV2Report::class)->sourceGuard($data, $this->source."\nI do not hold AWS certification");
        $assessment = app(CvAiAssessment::class)->normalise($guarded);
        $this->assertSame('confirmed_gap', $assessment['recommendation_details'][0]['evidence_status']);
        $this->assertFalse($assessment['recommendation_details'][0]['confirmation_required']);
        $this->assertSame('critical', $assessment['warnings'][0]['severity']);
    }

    public function test_analyse_builds_recommendations_for_missing_credentials_and_rejects_false_quotes(): void
    {
        $this->configure();
        $assessment = $this->assessment();
        $assessment['requirements'][1]['evidence'] = 'Certified AWS expert';
        $assessment['requirements'][1]['original_status'] = 'matched';
        Http::fake(['api.openai.com/*' => Http::response($this->response(['summary' => 'Review.', 'recommendations' => [], 'assessment' => $assessment]))]);
        $result = (new CvAiRewriter)->analyse('PHP and mandatory AWS certification', $this->source);
        $this->assertSame('not_evidenced', $result['assessment']['requirements'][1]['original_status']);
        $this->assertSame(0.0, $result['assessment']['original_score']['certifications']);
        $this->assertNotEmpty($result['assessment']['recommendation_details']);
        $this->assertStringContainsString('if held', implode(' ', $result['assessment']['recommendations']));
        $this->assertSame('review_required', $result['assessment']['mandatory_requirements_status']);
    }

    public function test_staged_rewrite_independently_validates_and_preserves_missing_certification(): void
    {
        $this->configure();
        $baseline = app(CvAiAssessment::class)->normalise($this->assessment());
        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->response($this->draft()))->push($this->response($this->validation(), 200, 60))]);
        $result = (new CvAiRewriter);
        $draft = $result->rewrite('PHP and mandatory AWS certification', $this->source, [], $baseline);
        Http::assertSentCount(2);
        $this->assertSame(300, $result->lastUsage['input_tokens']);
        $this->assertSame(110, $result->lastUsage['output_tokens']);
        $this->assertTrue($draft['post_revamp_validation']['approved']);
        $this->assertSame(100.0, $draft['keyword_coverage_percentage']);
        $this->assertNotEmpty($draft['ai_changes']);
        $this->assertNotEmpty($draft['remaining_warnings']);
        $this->assertStringNotContainsString('AWS', $draft['rewritten_resume']);
        $this->assertSame($baseline['original_score']['total'], $draft['assessment']['original_score']['total']);
        Http::assertSent(fn ($request) => $request['input'][0]['content'] && str_contains($request['input'][0]['content'], 'VALIDATE_REVAMP'));
    }

    public function test_direct_rewrite_without_a_scan_runs_analyse_revamp_validate_in_order(): void
    {
        $this->configure();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response(['summary' => 'Review.', 'recommendations' => [], 'assessment' => $this->assessment()]))
            ->push($this->response($this->draft()))->push($this->response($this->validation()))]);
        $service = new CvAiRewriter;
        $service->rewrite('PHP and AWS certification', $this->source, []);
        Http::assertSentCount(3);
        $this->assertSame(300, $service->lastUsage['input_tokens']);
        $actions = Http::recorded()->map(fn ($pair) => json_decode($pair[0]['input'][0]['content'], true)['ACTION'])->all();
        $this->assertSame(['ANALYSE', 'REVAMP', 'VALIDATE_REVAMP'], $actions);
        $budgets = Http::recorded()->map(fn ($pair) => $pair[0]['max_output_tokens'])->all();
        $this->assertSame([16000, 18000, 20000], $budgets);
    }

    public function test_v2_scan_runs_in_background_and_truncation_refunds_without_a_paid_retry(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessCvAi::class]);
        $user = \App\Models\User::factory()->create();
        $start = $this->actingAs($user)->postJson('/checker/scan', ['job_description' => 'PHP and mandatory AWS certification',
            'resume_text' => $this->source])->assertStatus(202);
        Http::assertNothingSent();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete', 'model' => 'gpt-6.1-sol',
            'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => ['input_tokens' => 100, 'output_tokens' => 16000], 'output' => []])]);
        $worker = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\ProcessCvAi::class)->first();
        $this->assertSame('openai', $worker->provider);
        $worker->handle();
        $worker->handle();
        $this->getJson($start->json('status_url'))->assertStatus(503)->assertJsonPath('credits_remaining', 15)
            ->assertJsonPath('status', 'failed')->assertJsonFragment(['message' => 'The AI report was cut short before it finished. Please contact support before submitting the same request again. Your credits were refunded.']);
        Http::assertSentCount(1);
        $this->assertSame(0, $user->analyses()->count());
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'status' => 'failed', 'credits_charged' => 0]);
    }

    public function test_validator_refusal_blocks_the_draft_instead_of_saving_fabricated_credentials(): void
    {
        $this->configure();
        $validation = $this->validation();
        $validation['approved'] = false;
        $validation['issues'] = ['An unsupported certification was inserted.'];
        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->response($this->draft()))->push($this->response($validation))]);
        $this->expectException(\RuntimeException::class);
        (new CvAiRewriter)->rewrite('PHP and AWS certification', $this->source, [], app(CvAiAssessment::class)->normalise($this->assessment()));
    }

    public function test_browser_rewrite_queues_openai_validation_and_carries_the_audit_into_the_builder(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessCvAi::class]);
        $user = \App\Models\User::factory()->create();
        $job = 'PHP and mandatory AWS certification';
        $baseline = app(CvAiAssessment::class)->normalise($this->assessment());
        $start = $this->actingAs($user)->withSession(['cv_checker.scan' => [
            'signature' => \App\Services\CvDocumentIdentity::signature($job, $this->source), 'assessment' => $baseline,
        ]])->postJson('/checker/rewrite', ['job_description' => $job, 'resume_text' => $this->source, 'confirmed_keywords' => []])->assertStatus(202);
        Http::assertNothingSent();
        $worker = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\ProcessCvAi::class)->first();
        $this->assertSame('openai', $worker->provider);
        $this->assertSame('gpt-6.1-sol', $worker->model);
        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->response($this->draft()))->push($this->response($this->validation()))]);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $worker->handle();
        $result = $this->actingAs($user)->getJson($start->json('status_url'))->assertOk()->assertJsonPath('credits_remaining', 5)
            ->assertJsonPath('post_revamp_validation.approved', true)->assertSessionHas('cv_checker.generated.report.ai_changes');
        $draft = $result->json();
        $this->post('/checker/edit', ['resume_text' => $draft['rewritten_resume'], 'original_resume_text' => $this->source,
            'job_description' => $job, 'generated' => 1, 'structured_resume' => json_encode($draft['structured_resume']),
            'ai_changes' => $draft['changes']])->assertRedirect(route('builder.start'));
        $this->get(route('builder.start'))->assertOk()->assertSee('What AI changed')->assertSee('Before:')
            ->assertSee('After:')->assertSee('Source evidence:')->assertSee('Remaining Warnings')->assertSee('AWS certification');
        Http::assertSentCount(2);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'provider' => 'openai', 'status' => 'completed',
            'model' => 'gpt-6.1-sol', 'input_tokens' => 200, 'output_tokens' => 100, 'credits_charged' => 10]);
    }

    public function test_failed_independent_validation_refunds_the_entire_rewrite_without_a_builder_draft(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessCvAi::class]);
        $user = \App\Models\User::factory()->create();
        $job = 'PHP and mandatory AWS certification';
        $start = $this->actingAs($user)->withSession(['cv_checker.scan' => [
            'signature' => \App\Services\CvDocumentIdentity::signature($job, $this->source),
            'assessment' => app(CvAiAssessment::class)->normalise($this->assessment()),
        ]])->postJson('/checker/rewrite', ['job_description' => $job, 'resume_text' => $this->source, 'confirmed_keywords' => []])->assertStatus(202);
        $validation = $this->validation();
        $validation['approved'] = false;
        $validation['issues'] = ['An unsupported claim was found.'];
        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->response($this->draft()))->push($this->response($validation))]);
        $worker = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\ProcessCvAi::class)->first();
        $worker->handle();
        $worker->handle();
        $this->getJson($start->json('status_url'))->assertStatus(503)->assertJsonPath('credits_remaining', 15)
            ->assertSessionMissing('cv_checker.generated');
        Http::assertSentCount(2);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'status' => 'failed', 'credits_charged' => 0, 'input_tokens' => 200]);
    }

    public function test_supported_keyword_omission_is_rejected_even_when_the_validator_says_approved(): void
    {
        $validation = $this->validation();
        $validation['keyword_coverage'][0]['represented'] = false;
        $this->expectException(\RuntimeException::class);
        app(CvAiV2Report::class)->completeValidation($validation, app(CvAiAssessment::class)->normalise($this->assessment()), $this->draft()['structured_resume'], $this->source, []);
    }

    public function test_change_report_cannot_claim_text_that_is_absent_from_the_final_cv(): void
    {
        $validation = $this->validation();
        $validation['ai_changes'][0]['revised_text'] = 'Certified AWS architect';
        $this->expectException(\RuntimeException::class);
        app(CvAiV2Report::class)->completeValidation($validation, app(CvAiAssessment::class)->normalise($this->assessment()), $this->draft()['structured_resume'], $this->source, []);
    }
}
