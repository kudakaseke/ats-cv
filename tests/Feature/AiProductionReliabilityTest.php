<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiCreditService;
use App\Services\CvAiAssessment;
use App\Services\CvAiRewriter;
use App\Services\CvAiV2Report;
use App\Services\IncidentReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Fixtures\MasterCvV2;
use Tests\TestCase;

class AiProductionReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function providerFailures(): array
    {
        $completed = fn ($content) => ['status' => 'completed', 'model' => 'gpt-6.1-sol',
            'output' => [['type' => 'message', 'content' => [$content]]]];
        return [
            'invalid key' => [401, ['error' => ['message' => 'PRIVATE_KEY']], true],
            'access denied' => [403, ['error' => ['message' => 'PRIVATE_ACCESS']], true],
            'unavailable model' => [404, ['error' => ['message' => 'PRIVATE_MODEL']], true],
            'quota exhausted' => [429, ['error' => ['code' => 'insufficient_quota', 'message' => 'PRIVATE_BILLING']], true],
            'rate limited' => [429, ['error' => ['code' => 'rate_limit_exceeded']], false],
            'provider unavailable' => [503, ['error' => ['message' => 'PRIVATE_PROVIDER']], false],
            'invalid json' => [200, $completed(['type' => 'output_text', 'text' => '{']), false],
            'refusal' => [200, $completed(['type' => 'refusal', 'refusal' => 'PRIVATE_REASON']), false],
            'output cut short' => [200, ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']], true],
            'empty output' => [200, ['status' => 'completed', 'output' => []], false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerFailures')]
    public function test_failed_provider_requests_refund_once_and_do_not_repeat_on_replay(int $status, array $body, bool $contactSupport): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key',
            'services.openai.model' => 'gpt-6.1-sol', 'services.openai.validate_revamp' => false]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($body, $status)]);
        $user = User::factory()->create();
        $input = ['job_description' => 'PHP and mandatory AWS certification', 'resume_text' => MasterCvV2::SOURCE,
            'operation_id' => (string) Str::uuid()];
        $response = $this->actingAs($user)->postJson('/checker/scan', $input)->assertStatus(503)
            ->assertJsonPath('credits_remaining', 15)->assertDontSee('PRIVATE_')->assertDontSee('fake-key');
        if ($contactSupport) $response->assertSee('contact support');
        $this->postJson('/checker/scan', $input)->assertStatus(503);
        Http::assertSentCount(1);
        $this->assertSame(15, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertDatabaseCount('ai_usage_logs', 1);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'status' => 'failed', 'credits_charged' => 0]);
        $this->assertSame(0, $user->analyses()->count());
    }

    public function test_second_tab_cannot_reserve_or_send_an_identical_inflight_scan(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.validate_revamp' => false]);
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create();
        $data = ['job_description' => 'PHP and mandatory AWS certification', 'resume_text' => MasterCvV2::SOURCE];
        app(AiCreditService::class)->reserve($user, 'analyse', (string) Str::uuid(), $data);
        $this->actingAs($user)->postJson('/checker/scan', [...$data, 'operation_id' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(10, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertDatabaseCount('ai_operations', 1);
        Http::assertNothingSent();
    }

    public function test_diagnostic_cache_outage_does_not_prevent_refund_or_replace_the_timeout_response(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key',
            'services.openai.model' => 'gpt-6.1-sol', 'services.openai.validate_revamp' => false]);
        \Illuminate\Support\Facades\Cache::partialMock()->shouldReceive('put')
            ->withArgs(fn ($key) => str_starts_with($key, 'ai-failure:'))->once()->andThrow(new \RuntimeException('PRIVATE_CACHE'));
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('PRIVATE_TIMEOUT'));
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/checker/scan', ['job_description' => 'PHP and mandatory AWS certification',
            'resume_text' => MasterCvV2::SOURCE])->assertStatus(503)->assertJsonPath('credits_remaining', 15)
            ->assertSee('did not respond in time')->assertDontSee('PRIVATE_');
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'status' => 'failed', 'credits_charged' => 0]);
    }

    public function test_current_skill_confirmation_can_support_coverage_without_changing_original_score(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key',
            'services.openai.model' => 'gpt-6.1-sol', 'services.openai.validate_revamp' => false]);
        $original = MasterCvV2::assessment();
        $original['requirements'][0]['original_status'] = $original['requirements'][0]['revamped_status'] = 'not_evidenced';
        $original['requirements'][0]['evidence'] = '';
        $baseline = app(CvAiAssessment::class)->normalise($original);
        $draft = [...MasterCvV2::draft(), ...MasterCvV2::validation()];
        $draft['assessment']['requirements'][0]['evidence'] = 'PHP';
        $draft['structured_resume']['summary'] = 'Software developer with PHP skills.';
        $draft['ai_changes'][0]['original_text'] = '';
        $draft['ai_changes'][0]['revised_text'] = 'Software developer with PHP skills.';
        $draft['ai_changes'][0]['supporting_evidence'] = 'PHP';
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(MasterCvV2::response($draft))]);
        $result = app(CvAiRewriter::class)->rewrite('PHP and mandatory AWS certification',
            "Alex Example\nSoftware Developer\nEducation\nDiploma in Computing", ['PHP'], $baseline);
        $this->assertSame($baseline['original_score']['total'], $result['assessment']['original_score']['total']);
        $this->assertContains('PHP', $result['structured_resume']['skills']);
        $this->assertTrue($result['post_revamp_validation']['approved']);
        Http::assertSentCount(1);
    }

    public function test_confirmation_cannot_establish_a_credential(): void
    {
        $validation = MasterCvV2::validation();
        $validation['keyword_coverage'][1]['supported'] = true;
        $validation['keyword_coverage'][1]['represented'] = true;
        $this->expectException(\RuntimeException::class);
        app(CvAiV2Report::class)->completeValidation($validation,
            app(CvAiAssessment::class)->normalise(MasterCvV2::assessment()),
            MasterCvV2::draft()['structured_resume'], MasterCvV2::SOURCE, ['AWS certification']);
    }

    public function test_rewrite_reuses_verified_scan_and_audits_terms_inserted_by_the_application(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key',
            'services.openai.model' => 'gpt-6.1-sol', 'services.openai.validate_revamp' => false]);
        $draft = [...MasterCvV2::draft(), ...MasterCvV2::validation()];
        unset($draft['assessment']);
        $draft['structured_resume']['skills'] = [];
        $draft['keyword_coverage'][0]['represented'] = false;
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(MasterCvV2::response($draft))]);
        $baseline = app(CvAiAssessment::class)->normalise(MasterCvV2::assessment());
        $result = app(CvAiRewriter::class)->rewrite('PHP and mandatory AWS certification', MasterCvV2::SOURCE, [], $baseline);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! isset($request['text']['format']['schema']['properties']['assessment']));
        $this->assertSame($baseline['original_score']['total'], $result['assessment']['original_score']['total']);
        $this->assertNotEmpty($result['remaining_warnings']);
        $this->assertTrue(collect($result['ai_changes'])->contains(fn ($change) => $change['change_type'] === 'supported_term_added' && $change['revised_text'] === 'PHP'));
        $this->assertSame(100.0, $result['keyword_coverage_percentage']);
    }

    public function test_connection_diagnostics_keep_numeric_timings_and_discard_sensitive_context(): void
    {
        config(['sentry.dsn' => null]);
        $cause = new \GuzzleHttp\Exception\ConnectException('PRIVATE_CV PRIVATE_KEY',
            new \GuzzleHttp\Psr7\Request('POST', 'https://example.invalid/PRIVATE_KEY'), null,
            ['errno' => 28, 'total_time' => 120.001, 'connect_time' => 0.05,
                'url' => 'PRIVATE_URL', 'error' => 'PRIVATE_ERROR', 'request_header' => 'PRIVATE_AUTH']);
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('incidents')->andReturn($logger);
        $logger->shouldReceive('error')->once()->with('Application failure', \Mockery::on(function ($context) {
            $this->assertSame(['errno' => 28, 'total_time' => 120.001, 'connect_time' => 0.05], $context['transport']);
            $this->assertStringNotContainsString('PRIVATE_', json_encode($context));
            return true;
        }));
        app(IncidentReporter::class)->report(new \Illuminate\Http\Client\ConnectionException('PRIVATE_MESSAGE', 0, $cause));
    }
}
