<?php

namespace Tests\Feature;

use App\Jobs\ProcessCvAi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackgroundCvAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Sleep::fake();
        config(['services.cv_ai.provider' => 'kimi', 'services.kimi.model' => 'kimi-k3.1-pro',
            'services.kimi.key' => 'fake-key', 'services.kimi.background' => true]);
        Queue::fake([ProcessCvAi::class]);
        Http::preventStrayRequests();
    }

    private function input(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'job_description' => 'Skills: PHP, Laravel',
            'resume_text' => "Alex Example\nPHP Developer\nSkills\nPHP, Laravel"];
    }

    public function test_scan_returns_immediately_then_polls_the_completed_kimi_result(): void
    {
        $user = User::factory()->create();
        $input = $this->input();
        $start = $this->actingAs($user)->postJson('/checker/scan', $input)->assertStatus(202);
        Http::assertNothingSent();
        $url = $start->json('status_url');
        $this->getJson($url)->assertStatus(202)->assertJsonPath('credits_remaining', 10);
        $job = Queue::pushed(ProcessCvAi::class)->first();
        $this->assertSame('kimi-k3.1-pro', $job->model);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);
        Http::fake(['api.moonshot.ai/*' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => json_encode(['summary' => 'Relevant PHP experience.', 'recommendations' => []])]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]])]);
        \Illuminate\Support\Facades\Auth::forgetGuards(); // A real worker has no browser login.
        $job->handle();
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('ai_analysis.summary', 'Relevant PHP experience.')
            ->assertSessionHas('cv_checker.scan')->assertJsonPath('credits_remaining', 10);
        $this->getJson($url)->assertOk();
        $job->handle(); // Redelivery must not call the paid provider again.
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $user->id, 'provider' => 'kimi',
            'model' => 'kimi-k3.1-pro', 'status' => 'completed', 'credits_charged' => 5]);
        $this->assertSame(1, $user->analyses()->count());
    }

    public function test_duplicate_pending_submissions_share_one_job_and_one_credit_reservation(): void
    {
        $this->actingAs(User::factory()->create());
        $input = $this->input();
        $first = $this->postJson('/checker/scan', $input)->assertStatus(202);
        $this->postJson('/checker/scan', $input)->assertStatus(202)->assertJsonPath('operation_id', $first->json('operation_id'));
        $input['operation_id'] = (string) Str::uuid();
        $this->postJson('/checker/scan', $input)->assertStatus(202)->assertJsonPath('operation_id', $first->json('operation_id'))
            ->assertJsonPath('credits_remaining', 10);
        Queue::assertPushed(ProcessCvAi::class, 1);
        $this->assertDatabaseCount('ai_operations', 1);
        Http::assertNothingSent();
    }

    public function test_status_is_private_to_the_operation_owner(): void
    {
        $first = $this->actingAs(User::factory()->create())->postJson('/checker/scan', $this->input())->assertStatus(202);
        $this->actingAs(User::factory()->create())->getJson($first->json('status_url'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_job_that_waited_too_long_refunds_without_calling_the_provider(): void
    {
        $this->actingAs(User::factory()->create());
        $start = $this->postJson('/checker/scan', $this->input())->assertStatus(202);
        $job = Queue::pushed(ProcessCvAi::class)->first();
        $job->expiresAt = time() + 300;
        $job->handle();
        $this->getJson($start->json('status_url'))->assertStatus(503)->assertJsonPath('credits_remaining', 15);
        Http::assertNothingSent();
    }

    public function test_polling_recovers_an_abandoned_reservation_once(): void
    {
        $this->actingAs(User::factory()->create());
        $start = $this->postJson('/checker/scan', $this->input())->assertStatus(202);
        DB::table('ai_operations')->where('id', $start->json('operation_id'))->update(['created_at' => now()->subMinutes(11)]);
        $this->getJson($start->json('status_url'))->assertStatus(503)->assertJsonPath('credits_remaining', 15);
        $this->getJson($start->json('status_url'))->assertStatus(503)->assertJsonPath('credits_remaining', 15);
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'failed_usage_refund')->count());
        Http::assertNothingSent();
    }

    public function test_background_timeout_refunds_once_without_automatically_repeating_the_api_call(): void
    {
        $user = User::factory()->create();
        $start = $this->actingAs($user)->postJson('/checker/scan', $this->input())->assertStatus(202);
        Http::fake(['api.moonshot.ai/*' => Http::failedConnection()]);
        $job = Queue::pushed(ProcessCvAi::class)->first();
        $job->handle();
        $job->handle();
        $job->failed(null);
        $this->getJson($start->json('status_url'))->assertStatus(503)->assertJsonPath('status', 'failed')
            ->assertJsonPath('credits_remaining', 15);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'failed_usage_refund')->count());
    }

    public function test_background_rewrite_restores_the_builder_session_when_polled(): void
    {
        $user = User::factory()->create();
        $input = [...$this->input(), 'confirmed_keywords' => []];
        $start = $this->actingAs($user)->postJson('/checker/rewrite', $input)->assertStatus(202);
        $draft = ['name' => 'Alex Example', 'role' => 'PHP Developer', 'email' => '', 'phone' => '', 'location' => '',
            'website' => '', 'summary' => 'PHP developer.', 'references' => '', 'skills' => ['PHP', 'Laravel'],
            'core_strengths' => [], 'competencies' => [], 'experience' => [], 'education' => [],
            'achievements' => [], 'courses' => [], 'languages' => [], 'custom_sections' => []];
        Http::fake(['api.moonshot.ai/*' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => json_encode(['structured_resume' => $draft, 'changes' => [], 'unsupported_keywords' => []])]]]])]);
        Queue::pushed(ProcessCvAi::class)->first()->handle();
        $this->getJson($start->json('status_url'))->assertOk()->assertJsonPath('structured_resume.name', 'Alex Example')
            ->assertSessionHas('cv_checker.generated.original_text', $input['resume_text']);
        Http::assertSentCount(1);
    }
}
