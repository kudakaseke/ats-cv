<?php

namespace Tests\Feature;

use App\Jobs\RefreshPayment;
use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\CvReviewComparison;
use App\Services\PlanService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class PerformanceReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_repeated_display_reads_do_not_repeat_wallet_queries_and_mutations_invalidate_them(): void
    {
        $user = User::factory()->create();
        $credits = app(AiCreditService::class);
        DB::enableQueryLog();
        $first = $credits->displayWallet($user);
        $count = count(DB::getQueryLog());
        $this->assertSame($first, $credits->displayWallet($user));
        $this->assertCount($count, DB::getQueryLog());
        $credits->topup($user, 5, 'query-test-topup');
        $this->assertSame($first['credits'] + 5, $credits->displayWallet($user)['credits']);
    }

    public function test_template_entitlements_select_distinct_template_names_without_loading_whole_cvs(): void
    {
        $user = User::factory()->create();
        for ($index = 0; $index < 30; $index++) {
            $user->resumes()->create(['name' => 'Fixture', 'content' => ['template' => 'classic', 'summary' => str_repeat('private ', 1000)]]);
        }
        DB::enableQueryLog();
        $this->assertContains('classic', app(PlanService::class)->templateIds($user));
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(3, count($queries));
        $resumeQuery = collect($queries)->first(fn ($query) => str_contains($query['query'], 'resumes'))['query'];
        $this->assertStringContainsString('json_extract', strtolower($resumeQuery));
        $this->assertStringContainsString('distinct', strtolower($resumeQuery));
    }

    public function test_legacy_rewrite_recovery_uses_two_queries_for_twenty_operations_and_stays_owner_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->analyses()->create(['job_description' => "Skills\nPHP", 'status' => 'completed', 'results' => ['matched' => ['PHP'], 'missing' => []]]);
        $other->analyses()->create(['job_description' => 'Private other advert', 'status' => 'completed', 'results' => ['matched' => ['PHP'], 'missing' => []]]);
        for ($index = 0; $index < 20; $index++) {
            DB::table('ai_operations')->insert([
                'user_id' => $user->id, 'operation_id' => (string) Str::uuid(), 'feature' => 'revamp',
                'request_hash' => hash('sha256', (string) $index), 'status' => 'completed',
                'subscription_spent' => 0, 'topup_spent' => 0,
                'result' => Crypt::encryptString(json_encode(['rewritten_resume' => 'Revised CV', 'before' => ['matched' => [$index === 0 ? 'PHP' : 'other'], 'missing' => []]])),
                'created_at' => now()->addMinute(), 'updated_at' => now(),
            ]);
        }
        DB::enableQueryLog();
        $comparison = app(CvReviewComparison::class)->refreshForUser(['original_text' => 'Original PHP CV', 'revised_text' => 'Revised CV'], $user->id);
        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame("Skills\nPHP", $comparison['job_description']);
    }

    public function test_payment_polling_queues_one_verification_without_waiting_for_provider(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['performance.queue_payment_checks' => true, 'performance.background_connection' => 'database']);
        $payment = $this->payment(User::factory()->create());
        $this->actingAs(User::find($payment->user_id));
        $this->get('/payments/'.$payment->reference)->assertOk();
        $this->get('/payments/'.$payment->reference)->assertOk();
        Queue::assertPushed(RefreshPayment::class, 1);
        Http::assertNothingSent();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_verification_email_is_encrypted_queued_and_expired_codes_are_not_delivered(): void
    {
        Queue::fake();
        config(['performance.background_connection' => 'database']);
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        Queue::assertPushed(SendQueuedNotifications::class, function ($job) use ($user) {
            $this->assertTrue($job->shouldBeEncrypted);
            $this->assertSame('mail', $job->queue);
            $this->assertTrue($job->notification->shouldSend($user, 'mail'));
            $this->travel(11)->minutes();
            $this->assertFalse($job->notification->shouldSend($user, 'mail'));

            return true;
        });
    }

    public function test_payment_return_limit_blocks_repeated_work_before_provider_calls(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['performance.queue_payment_checks' => true]);
        $payment = $this->payment(User::factory()->create());
        $this->actingAs(User::find($payment->user_id));
        for ($index = 0; $index < 20; $index++) {
            $this->get('/payments/'.$payment->reference)->assertOk();
        }
        $this->get('/payments/'.$payment->reference)->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
    }

    public function test_errors_have_a_support_reference_without_logging_cv_or_credentials(): void
    {
        config(['app.debug' => false]);
        $captured = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->withArgs(function ($message, $context) use (&$captured) {
            $captured = $context;
            $this->assertStringNotContainsString('PRIVATE_CV_AND_API_SECRET', json_encode($context));

            return true;
        });
        Log::shouldReceive('channel')->with('incidents')->andReturn($logger);
        Route::get('/_test/incident', fn () => throw new \RuntimeException('PRIVATE_CV_AND_API_SECRET'));
        $response = $this->getJson('/_test/incident')->assertStatus(500)->assertJsonStructure(['message', 'reference'])
            ->assertDontSee('PRIVATE_CV_AND_API_SECRET');
        $this->assertSame($captured['reference'], $response->json('reference'));
        $response->assertHeader('X-Error-Reference', $captured['reference']);
        $this->assertSame(\RuntimeException::class, $captured['type']);
    }

    public function test_cron_worker_does_not_overlap_and_incident_reader_rejects_path_traversal(): void
    {
        $lock = Cache::lock('bounded-background-worker', 240);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('app:work')->expectsOutput('A background worker is already running.')->assertSuccessful();
        } finally {
            $lock->release();
        }
        $this->artisan('app:errors', ['--date' => '../../.env'])->expectsOutput('Use a YYYY-MM-DD date.')->assertFailed();
    }

    private function payment(User $user): BillingPayment
    {
        return BillingPayment::create(['user_id' => $user->id, 'reference' => (string) Str::uuid(),
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
            'provider' => 'paynow', 'mode' => 'test', 'amount_cents' => 475, 'currency' => 'USD', 'billing_interval' => 'monthly']);
    }
}
