<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cv_analytics_distinguishes_missing_scores_from_zero(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.section', 'cv'))
            ->assertOk()->assertSeeText('Average recorded Wording Match in selected periodNo recorded scores');
    }

    public function test_local_preview_can_open_every_admin_section_and_each_has_real_section_description(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $admin = $this->admin();
        foreach (['users' => 'User Analytics', 'revenue' => 'Subscriptions & Revenue', 'cv' => 'CV Analytics', 'ai' => 'AI Usage & Costs', 'credits' => 'Credit Analytics', 'accounts' => 'Users Management', 'reviews' => 'Reviews & Feedback', 'system' => 'System Monitoring'] as $slug => $title) {
            $this->actingAs($admin, 'admin')->get(route('admin.section', $slug))->assertOk()->assertSee($title)
                ->assertSee('>Today</a>', false)
                ->assertViewHas('period', fn (array $period) => $period['from'] === '2026-11-17' && $period['to'] === '2026-11-17');
        }
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.request']);
    }

    public function test_super_admin_sidebar_uses_registered_icons_for_every_navigation_section(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->actingAs($this->admin(), 'admin')->get('/laravel/access-admin/dashboard')->assertOk()
            ->assertSee('data-lucide="chart-no-axes-combined"', false)
            ->assertSee('data-lucide="users-round"', false)
            ->assertSee('data-lucide="credit-card"', false)
            ->assertSee('data-lucide="file-text"', false)
            ->assertSee('data-lucide="cpu"', false)
            ->assertSee('data-lucide="coins"', false)
            ->assertSee('data-lucide="user-cog"', false)
            ->assertSee('data-lucide="message-square"', false)
            ->assertSee('data-lucide="activity"', false);
    }

    public function test_section_date_range_shows_daily_activity_graph(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $admin = $this->admin();
        User::factory()->create(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->actingAs($admin, 'admin')->get(route('admin.section', ['section' => 'users', 'from' => '2026-11-14', 'to' => '2026-11-15']))
            ->assertOk()->assertSee('New registrations per day')->assertSee('2026-11-14: 1')->assertSee('name="from" value="2026-11-14"', false);
    }

    public function test_admin_requests_are_not_recorded_as_customer_activity(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk();
        $this->assertDatabaseMissing('account_sessions', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('audit_events', ['user_id' => $admin->id, 'event' => 'account.request']);
    }

    public function test_customer_activity_graph_excludes_admin_events(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create();
        DB::table('audit_events')->whereIn('user_id', [$admin->id, $customer->id])->delete();
        foreach ([$admin, $customer] as $user) {
            DB::table('audit_events')->insert([
                'user_id' => $user->id, 'actor_id' => $user->id, 'event' => 'account.request',
                'subject_type' => 'account', 'subject_id' => (string) $user->id, 'source' => 'web',
                'details' => json_encode([]), 'created_at' => now(),
            ]);
        }

        $this->actingAs($admin, 'admin')->get(route('admin.section', 'accounts'))
            ->assertOk()->assertViewHas('dailyChart', fn (array $chart) => $chart['points'][0]['value'] === 1.0);
    }

    public function test_cv_analytics_metrics_and_records_follow_the_selected_calendar_range(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create();
        $today = now()->startOfDay()->addHours(3);
        $yesterday = now()->subDay()->startOfDay()->addHours(3);
        $todayResume = DB::table('resumes')->insertGetId([
            'user_id' => $customer->id, 'name' => 'Today CV', 'source' => 'created', 'status' => 'ready',
            'original_path' => 'resumes/today.pdf', 'created_at' => $today, 'updated_at' => $today,
        ]);
        $yesterdayResume = DB::table('resumes')->insertGetId([
            'user_id' => $customer->id, 'name' => 'Yesterday CV', 'source' => 'created', 'status' => 'ready',
            'original_path' => 'resumes/yesterday.pdf', 'created_at' => $yesterday, 'updated_at' => $yesterday,
        ]);
        foreach ([$today, $yesterday] as $createdAt) {
            DB::table('audit_events')->insert(['user_id' => $customer->id, 'actor_id' => $customer->id,
                'event' => 'account.request', 'subject_type' => 'Route', 'subject_id' => 'checker.upload',
                'source' => 'checker.upload', 'details' => json_encode(['http_status' => 200]), 'created_at' => $createdAt]);
        }
        foreach ([[$todayResume, $today, 82], [$yesterdayResume, $yesterday, 61]] as [$resumeId, $createdAt, $score]) {
            DB::table('cv_analyses')->insert([
                'user_id' => $customer->id, 'resume_id' => $resumeId, 'job_title' => 'Analyst',
                'job_description' => 'Experience and communication', 'status' => 'completed', 'score' => $score,
                'results' => json_encode(['missing' => ['Excel proficiency']]), 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }
        DB::table('ai_operations')->insert([
            'user_id' => $customer->id, 'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'feature' => 'revamp', 'request_hash' => str_repeat('a', 64), 'status' => 'completed',
            'subscription_spent' => 1, 'topup_spent' => 0, 'created_at' => $today, 'updated_at' => $today,
        ]);

        $todayResponse = $this->actingAs($admin, 'admin')->get(route('admin.section', [
            'section' => 'cv', 'from' => '2026-11-15', 'to' => '2026-11-15',
        ]))->assertOk()
            ->assertSeeText('Successful signed-in CV imports in selected period1')
            ->assertSeeText('Completed ATS scans in selected period1')
            ->assertSeeText('CV revamps in selected period1')
            ->assertSeeText('CV creations in selected period1')
            ->assertSeeText('Average recorded Wording Match in selected period82')
            ->assertSee('Today CV', false)
            ->assertDontSee('Yesterday CV', false)
            ->assertDontSee('Frequent missing requirements in selected period');

        $this->get(route('admin.section', [
            'section' => 'cv', 'from' => '2026-11-14', 'to' => '2026-11-14',
        ]))->assertOk()
            ->assertSeeText('Successful signed-in CV imports in selected period1')
            ->assertSeeText('Completed ATS scans in selected period1')
            ->assertSeeText('CV revamps in selected period0')
            ->assertSeeText('Average recorded Wording Match in selected period61')
            ->assertSee('Yesterday CV', false)
            ->assertDontSee('Today CV', false);

    }

    public function test_user_analytics_metrics_follow_the_selected_calendar_range(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $admin = $this->admin();
        $todayUser = User::factory()->create(['created_at' => now()->startOfDay()->addHours(3), 'updated_at' => now()]);
        $existingUser = User::factory()->create(['created_at' => now()->subDays(10), 'updated_at' => now()]);
        $yesterdayUser = User::factory()->create(['created_at' => now()->subDay()->startOfDay()->addHours(3), 'updated_at' => now()]);
        $customerIds = [$todayUser->id, $existingUser->id, $yesterdayUser->id];
        DB::table('account_sessions')->whereIn('user_id', $customerIds)->delete();
        DB::table('audit_events')->whereIn('user_id', $customerIds)->delete();

        DB::table('account_sessions')->insert([
            ['id' => 'today-existing-session', 'user_id' => $existingUser->id, 'driver' => 'database', 'last_activity' => now()->startOfDay()->addHours(4)->timestamp],
            ['id' => 'yesterday-session', 'user_id' => $yesterdayUser->id, 'driver' => 'database', 'last_activity' => now()->subDay()->startOfDay()->addHours(4)->timestamp],
        ]);
        DB::table('audit_events')->insert([
            'user_id' => $todayUser->id, 'actor_id' => $todayUser->id, 'event' => 'account.request',
            'subject_type' => 'account', 'subject_id' => (string) $todayUser->id, 'source' => 'web',
            'details' => json_encode([]), 'created_at' => now()->startOfDay()->addHours(5),
        ]);

        $today = $this->actingAs($admin, 'admin')->get(route('admin.section', [
            'section' => 'users', 'from' => '2026-11-15', 'to' => '2026-11-15',
        ]))->assertOk()
            ->assertSee('All-time users')
            ->assertSee('New users in selected period')
            ->assertSee('Active users in selected period')
            ->assertSee('Returning users in selected period')
            ->assertSeeText('New users in selected period1')
            ->assertSeeText('Active users in selected period2')
            ->assertSeeText('Returning users in selected period1')
            ->assertSee('Current snapshot across all');

        $yesterday = $this->get(route('admin.section', [
            'section' => 'users', 'from' => '2026-11-14', 'to' => '2026-11-14',
        ]))->assertOk()
            ->assertSeeText('New users in selected period1')
            ->assertSeeText('Active users in selected period1')
            ->assertSeeText('Returning users in selected period0');

        $this->assertStringNotContainsString('2026-11-15: 1', $yesterday->getContent());
        $this->assertStringContainsString('2026-11-15: 1', $today->getContent());
    }

    public function test_ai_usage_metrics_and_daily_costs_follow_the_selected_local_calendar_day(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true, 'app.timezone' => 'Africa/Johannesburg']);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create();
        $todayLocal = now()->startOfDay()->addMinutes(30);
        $yesterdayLocal = now()->subDay()->startOfDay()->addMinutes(30);
        foreach ([['today', $todayLocal, 10, 20, 1_500_000], ['yesterday', $yesterdayLocal, 30, 40, 2_500_000]] as [$key, $createdAt, $input, $output, $cost]) {
            $operationId = DB::table('ai_operations')->insertGetId([
                'user_id' => $customer->id, 'operation_id' => \Illuminate\Support\Str::uuid()->toString(),
                'feature' => 'analyse', 'request_hash' => hash('sha256', $key), 'status' => 'completed',
                'subscription_spent' => 1, 'topup_spent' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            DB::table('ai_usage_logs')->insert([
                'user_id' => $customer->id, 'ai_operation_id' => $operationId, 'feature' => 'analyse',
                'provider' => 'openai', 'model' => 'gpt-'.$key, 'input_tokens' => $input, 'output_tokens' => $output,
                'credits_charged' => 1, 'status' => 'completed', 'total_cost_micros' => $cost,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        $today = $this->actingAs($admin, 'admin')->get(route('admin.section', [
            'section' => 'ai', 'from' => '2026-11-15', 'to' => '2026-11-15',
        ]))->assertOk()
            ->assertSeeText('AI requests in selected period1')
            ->assertSeeText('Input tokens in selected period10')
            ->assertSeeText('Output tokens in selected period20')
            ->assertSeeText('Known AI cost in selected periodUS$1.5000')
            ->assertSee('gpt-today')
            ->assertSee('Cost (USD)')->assertSee('$1.50')->assertDontSee('micros USD')
            ->assertDontSee('gpt-yesterday');
        $this->assertStringContainsString('2026-11-15</td><td>US$1.5000', $today->getContent());

        $this->get(route('admin.section', ['section' => 'ai', 'from' => '2026-11-14', 'to' => '2026-11-14']))
            ->assertOk()->assertSeeText('Input tokens in selected period30')
            ->assertSeeText('Known AI cost in selected periodUS$2.5000')
            ->assertSee('gpt-yesterday')->assertDontSee('gpt-today');
    }

    public function test_ai_cost_totals_identify_unpriced_requests_in_the_same_model_group(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $customer = User::factory()->create();
        foreach ([70_000, null] as $cost) {
            $operationId = DB::table('ai_operations')->insertGetId(['user_id' => $customer->id,
                'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'feature' => 'analyse',
                'request_hash' => hash('sha256', (string) \Illuminate\Support\Str::uuid()), 'status' => 'completed',
                'subscription_spent' => 1, 'topup_spent' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_usage_logs')->insert(['user_id' => $customer->id, 'ai_operation_id' => $operationId,
                'feature' => 'analyse', 'provider' => 'openai', 'model' => 'same-model', 'input_tokens' => 1,
                'output_tokens' => 1, 'credits_charged' => 1, 'status' => 'completed', 'total_cost_micros' => $cost,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($this->admin(), 'admin')->get(route('admin.section', 'ai'))->assertOk()
            ->assertSeeText('Requests without known cost1')->assertSee('$0.07 (1 unpriced)');
    }

    public function test_credit_analytics_uses_period_transactions_and_labels_current_balances_as_snapshots(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create();
        DB::table('ai_credit_transactions')->whereIn('user_id', [$admin->id, $customer->id])->delete();
        foreach ([['today-credit', 'subscription_grant', 5, 0, now()->startOfDay()->addHours(3)],
            ['yesterday-credit', 'usage', -3, 0, now()->subDay()->startOfDay()->addHours(3)]] as [$reference, $type, $subscriptionDelta, $topupDelta, $createdAt]) {
            DB::table('ai_credit_transactions')->insert([
                'user_id' => $customer->id, 'reference' => $reference, 'type' => $type,
                'subscription_delta' => $subscriptionDelta, 'topup_delta' => $topupDelta,
                'debt_delta' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        $this->actingAs($admin, 'admin')->get(route('admin.section', [
            'section' => 'credits', 'from' => '2026-11-15', 'to' => '2026-11-15',
        ]))->assertOk()
            ->assertSeeText('Credits added in selected period5')
            ->assertSeeText('Credits deducted in selected period0')
            ->assertSeeText('Credit transactions in selected period1')
            ->assertSee('Credits remaining now')
            ->assertSee('today-credit')
            ->assertDontSee('yesterday-credit');

        $this->get(route('admin.section', ['section' => 'credits', 'from' => '2026-11-14', 'to' => '2026-11-14']))
            ->assertOk()->assertSeeText('Credits added in selected period0')
            ->assertSeeText('Credits deducted in selected period3')
            ->assertSee('yesterday-credit')->assertDontSee('today-credit');
    }

    public function test_new_customer_registration_and_ats_scan_are_visible_in_super_admin_analytics(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true,
            'services.openai.key' => 'fake-test-key', 'services.openai.model' => 'gpt-4.1']);
        Mail::fake();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                'text' => json_encode(['summary' => 'The candidate has relevant IT support experience.', 'recommendations' => []])]]]],
        ])]);

        $this->post('/register', ['name' => 'Audit Test Customer', 'email' => 'audit.customer@example.test',
            'password' => 'Testing-Password-2026', 'password_confirmation' => 'Testing-Password-2026'])
            ->assertRedirect(route('verification.notice'));
        $customer = User::where('email', 'audit.customer@example.test')->firstOrFail();
        $customer->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($customer)->postJson('/checker/scan', [
            'job_description' => "IT Support Analyst\nRequirements: Windows, networking, troubleshooting",
            'resume_text' => "Audit Test Customer\nSkills\nWindows, networking, troubleshooting",
        ])->assertOk()->assertJsonStructure(['analysis_id']);

        $this->assertDatabaseHas('audit_events', ['user_id' => $customer->id, 'event' => 'account.request']);
        $this->assertDatabaseHas('cv_analyses', ['user_id' => $customer->id, 'status' => 'completed']);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $customer->id, 'feature' => 'analyse',
            'status' => 'completed', 'input_tokens' => 120, 'output_tokens' => 40]);

        Auth::guard('web')->logout();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertSee('1 new in this period');
        $this->get(route('admin.section', 'users'))->assertOk()->assertSee('audit.customer@example.test');
        $this->get(route('admin.section', 'accounts'))->assertOk()->assertSee('audit.customer@example.test');
        $this->get(route('admin.section', 'cv'))->assertOk()->assertSee('audit.customer@example.test')->assertSee('Recorded Wording Match');
        $this->get(route('admin.section', 'ai'))->assertOk()->assertSee('AI requests in selected period')->assertSee('gpt-4.1');
        $this->get(route('admin.section', 'credits'))->assertOk()->assertSee('Credits deducted in selected period');
    }

    public function test_flattened_bullet_scan_and_revamp_are_recorded_once_in_admin_analytics(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true,
            'services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-test-key', 'services.openai.model' => 'gpt-4.1']);
        $structured = [];
        foreach (app(\App\Services\CvStructuredResume::class)->schema()['properties'] as $name => $property) {
            $structured[$name] = $property['type'] === 'STRING' ? '' : [];
        }
        $structured['name'] = 'QA Applicant';
        $structured['skills'] = ['SQL', 'Python', 'excel'];
        Http::fake(function ($request) use ($structured) {
            $isRewrite = isset($request['text']['format']['schema']['properties']['structured_resume']);
            $result = $isRewrite ? ['structured_resume' => $structured, 'changes' => ['Added a user-confirmed skill.'], 'unsupported_keywords' => []]
                : ['summary' => 'Review complete.', 'recommendations' => []];
            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]]);
        });
        $customer = User::factory()->create();
        $job = "Skills: \u{2022} SQL \u{2022} Python \u{2022} Excel";
        $resume = "QA Applicant\nSkills\nSQL\nPython";
        $scanId = (string) \Illuminate\Support\Str::uuid();
        $rewriteId = (string) \Illuminate\Support\Str::uuid();
        $this->actingAs($customer)->post('/checker/upload', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('cv.txt', $resume)], ['Accept' => 'application/json'])->assertOk();
        $scan = ['job_description' => $job, 'resume_text' => $resume, 'operation_id' => $scanId];
        $this->postJson('/checker/scan', $scan)->assertOk()->assertJsonPath('total', 3)->assertJsonPath('keyword_score', 67)->assertJsonPath('credits_remaining', 10);
        $rewrite = ['job_description' => $job, 'resume_text' => $resume, 'confirmed_keywords' => ['excel'], 'operation_id' => $rewriteId];
        $draft = $this->postJson('/checker/rewrite', $rewrite)->assertOk()->assertJsonPath('before.keyword_score', 67)->assertJsonPath('after.keyword_score', 100)->assertJsonPath('credits_remaining', 0)->json();
        $this->postJson('/checker/scan', $scan)->assertOk()->assertJsonPath('credits_remaining', 0);
        $this->postJson('/checker/rewrite', $rewrite)->assertOk()->assertJsonPath('credits_remaining', 0);
        $this->post('/checker/edit', ['generated' => 1, 'resume_text' => $draft['rewritten_resume'], 'structured_resume' => json_encode($draft['structured_resume']),
            'original_resume_text' => $resume, 'job_description' => $job, 'ai_changes' => $draft['changes']])->assertRedirect(route('builder.start'))
            ->assertSessionHas('cv_builder.checker_result.keyword_before', 67)->assertSessionHas('cv_builder.checker_result.keyword_after', 100);
        $this->assertSame(1, DB::table('cv_analyses')->where('user_id', $customer->id)->count());
        $this->assertSame(2, DB::table('ai_usage_logs')->where('user_id', $customer->id)->count());
        $this->assertSame(15, DB::table('ai_usage_logs')->where('user_id', $customer->id)->sum('credits_charged'));
        $this->assertSame(1, DB::table('audit_events')->where('user_id', $customer->id)->where('source', 'checker.upload')->where('event', 'account.request')->count());
        Auth::guard('web')->logout();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.section', 'cv'))->assertOk()
            ->assertSeeText('Successful signed-in CV imports in selected period1')
            ->assertSeeText('Completed ATS scans in selected period1')
            ->assertSeeText('CV revamps in selected period1')
            ->assertSeeText('Average recorded Wording Match in selected period67');
        $this->get(route('admin.section', 'ai'))->assertOk()->assertSeeText('AI requests in selected period2');
        $this->get(route('admin.section', 'credits'))->assertOk()->assertSeeText('Credits deducted in selected period15');
    }

    public function test_failed_upload_and_scan_are_visible_to_admin_and_credits_are_refunded(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true,
            'services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-test-key', 'services.openai.model' => 'gpt-4.1']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 500)]);
        $customer = User::factory()->create();
        $this->actingAs($customer)->post('/checker/upload', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('bad.txt', "\xff\xfe"),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/checker/scan', ['job_description' => 'SQL Python', 'resume_text' => "QA Applicant\nSkills\nSQL"])
            ->assertStatus(503)->assertJsonPath('credits_remaining', 15)->assertJsonPath('operation_failed', true);
        $this->assertDatabaseHas('ai_operations', ['user_id' => $customer->id, 'feature' => 'analyse', 'status' => 'failed']);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $customer->id, 'feature' => 'analyse', 'status' => 'failed', 'credits_charged' => 0]);
        $this->assertDatabaseHas('audit_events', ['user_id' => $customer->id, 'event' => 'ai.failed']);
        Auth::guard('web')->logout();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.section', 'cv'))->assertOk()
            ->assertSeeText('Successful signed-in CV imports in selected period0')->assertSeeText('Failed signed-in CV imports in selected period1')
            ->assertSeeText('Failed ATS scan operations in selected period1')->assertSeeText('Completed ATS scans in selected period0');
        $this->get(route('admin.section', 'ai'))->assertOk()->assertSeeText('AI requests in selected period1')
            ->assertSeeText('Requests without known cost1')->assertSee('Not priced');
        $this->get(route('admin.section', 'system'))->assertOk()->assertSeeText('AI/API failures in selected period1');
    }

    public function test_late_scan_after_refund_does_not_create_a_completed_scan_record(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $customer = User::factory()->create();
        $rewriter = \Mockery::mock(\App\Services\CvAiRewriter::class)->makePartial();
        $rewriter->shouldReceive('analyse')->once()->andReturnUsing(function () use ($customer) {
            $operation = DB::table('ai_operations')->where('user_id', $customer->id)->where('status', 'reserved')->first();
            app(\App\Services\AiCreditService::class)->finish($customer, $operation->id, null);
            return ['summary' => 'A late provider response.', 'recommendations' => []];
        });
        $this->app->instance(\App\Services\CvAiRewriter::class, $rewriter);
        $this->actingAs($customer)->postJson('/checker/scan', ['job_description' => 'SQL Python', 'resume_text' => "QA Applicant\nSkills\nSQL"])
            ->assertStatus(503)->assertJsonPath('credits_remaining', 15)->assertJsonPath('operation_failed', true);
        $this->assertDatabaseMissing('cv_analyses', ['user_id' => $customer->id, 'status' => 'completed']);
        $this->assertSame(1, DB::table('ai_usage_logs')->where('user_id', $customer->id)->where('status', 'failed')->count());
        Auth::guard('web')->logout();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.section', 'cv'))->assertOk()
            ->assertSeeText('Completed ATS scans in selected period0')->assertSeeText('Failed ATS scan operations in selected period1');
    }

    public function test_late_revamp_after_refund_does_not_return_or_record_a_completed_draft(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $customer = User::factory()->create();
        $rewriter = \Mockery::mock(\App\Services\CvAiRewriter::class)->makePartial();
        $rewriter->shouldReceive('rewrite')->once()->andReturnUsing(function () use ($customer) {
            $operation = DB::table('ai_operations')->where('user_id', $customer->id)->where('status', 'reserved')->first();
            app(\App\Services\AiCreditService::class)->finish($customer, $operation->id, null);
            return ['rewritten_resume' => "QA Applicant\nSkills\nSQL", 'structured_resume' => [], 'changes' => [], 'unsupported_keywords' => []];
        });
        $this->app->instance(\App\Services\CvAiRewriter::class, $rewriter);
        $this->actingAs($customer)->postJson('/checker/rewrite', ['job_description' => 'SQL', 'resume_text' => "QA Applicant\nSkills\nSQL", 'confirmed_keywords' => []])
            ->assertStatus(503)->assertJsonPath('credits_remaining', 15)->assertJsonPath('operation_failed', true)->assertJsonMissingPath('rewritten_resume');
        $this->assertDatabaseHas('ai_operations', ['user_id' => $customer->id, 'feature' => 'revamp', 'status' => 'failed']);
        $this->assertSame(1, DB::table('ai_usage_logs')->where('user_id', $customer->id)->where('status', 'failed')->count());
        Auth::guard('web')->logout();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.section', 'cv'))->assertOk()
            ->assertSeeText('CV revamps in selected period0')->assertSeeText('Failed CV revamp operations in selected period1');
    }

    public function test_customer_feedback_submission_and_moderation_are_linked_to_the_admin(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $customer = User::factory()->create();
        $admin = $this->admin();
        $this->actingAs($customer)->post('/feedback', ['category' => 'suggestion', 'rating' => 5, 'body' => 'Please add another export format.'])
            ->assertRedirect()->assertSessionHas('status', 'Your rating was successfully sent. Thank you for your feedback.');
        $feedback = \Illuminate\Support\Facades\DB::table('customer_feedback')->first();
        Auth::guard('web')->logout();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->post(route('admin.feedback.moderate', $feedback->id), ['status' => 'approved', 'admin_reply' => 'Thank you.'])->assertRedirect()->assertSessionHas('status', 'Feedback moderation saved and linked to your super-admin account.');
        $this->assertDatabaseHas('customer_feedback', ['id' => $feedback->id, 'moderated_by' => $admin->id, 'status' => 'approved', 'testimonial_consent' => 0]);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'feedback.moderated', 'subject_id' => (string) $feedback->id]);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])->get(route('admin.section', 'reviews'))->assertOk()->assertSee('Please add another export format.');
        $this->get('/')->assertOk()->assertDontSee('Please add another export format.');
    }

    public function test_suspending_user_revokes_active_sessions_and_audits_admin_actor(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('account_sessions')->insert(['id' => 'session-id', 'user_id' => $customer->id, 'driver' => 'file', 'last_activity' => now()->timestamp]);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->post(route('admin.users.action', $customer->id), ['action' => 'suspend', 'reason' => 'Policy violation'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'account_status' => 'suspended']);
        $this->assertDatabaseMissing('account_sessions', ['id' => 'session-id']);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'user.suspend', 'subject_id' => (string) $customer->id]);
    }

    private function admin(array $attributes = []): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'super_admin', 'is_admin' => true, 'account_status' => 'active', 'password' => Hash::make('password'), ...$attributes])->save();
        return $admin;
    }
}
