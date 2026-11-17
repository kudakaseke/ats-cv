<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\User;
use App\Notifications\AdminLoginCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_customer_accounts_cannot_open_admin_pages(): void
    {
        $this->get('/laravel/access-admin/login')->assertOk();
        $this->get('/laravel/access-admin/2fa/setup')->assertNotFound();
        $this->post('/admin/login')->assertNotFound();
        $this->get('/admin/dashboard')->assertNotFound();
        $this->get('/laravel/access-admin/dashboard')->assertRedirect('/laravel/access-admin/login')
            ->assertSessionHas('status', 'Sign in to continue to the Super Admin Dashboard.');
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/laravel/access-admin/dashboard')->assertForbidden();
        $this->actingAs($customer, 'admin')->get('/laravel/access-admin/dashboard')->assertForbidden();
    }

    public function test_super_admin_must_confirm_emailed_code_before_dashboard_access(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->post('/laravel/access-admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $code = null;
        Notification::assertSentTo($admin, AdminLoginCode::class, function (AdminLoginCode $notification) use (&$code) {
            $code = $notification->code;

            return preg_match('/^\d{6}$/', $code) === 1;
        });
        $this->get('/laravel/access-admin/2fa/challenge')->assertOk()->assertSee('Check your email')
            ->assertSee(substr(strrchr($admin->email, '@') ?: '', 1));
        $this->get('/laravel/access-admin/dashboard')->assertRedirect(route('admin.2fa.challenge'));
        $this->post('/laravel/access-admin/2fa/verify', ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'auth.email_code_verified', 'actor_id' => $admin->id]);
        $this->get('/laravel/access-admin/dashboard')->assertOk()->assertSee('Overview')->assertSee('Super Admin Dashboard')->assertDontSee('workspace-shell');
    }

    public function test_local_dashboard_preview_is_read_only_and_billing_changes_require_email_code(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $admin = $this->admin();

        $this->post('/laravel/access-admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/laravel/access-admin/dashboard')->assertOk()->assertDontSee('Local preview mode');
        $this->actingAs($admin, 'admin')->get('/laravel/access-admin/billing')->assertOk()->assertDontSee('Local preview mode');
        $this->actingAs($admin, 'admin')->post('/laravel/access-admin/billing/not-a-real-reference/reconcile')
            ->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_local_dashboard_preview_does_not_mark_admin_email_code_verified(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $admin = $this->admin();

        $this->post('/laravel/access-admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/laravel/access-admin/dashboard')->assertOk()->assertDontSee('Local preview mode');
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => null])
            ->get('/laravel/access-admin/billing')->assertOk()->assertDontSee('Local preview mode');
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => null])
            ->post('/laravel/access-admin/billing/not-a-real-reference/review', [])->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_dashboard_preview_is_ignored_outside_local_environment(): void
    {
        config(['app.env' => 'production', 'admin.owner_email' => 'owner@example.com',
            'admin.dashboard_preview_without_2fa' => true]);
        $admin = $this->admin(['email' => 'owner@example.com']);

        $this->actingAs($admin, 'admin')->get('/laravel/access-admin/dashboard')->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_admin_login_checks_role_and_sends_email_code_after_password_authentication(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => false]);
        Notification::fake();
        $customer = User::factory()->create();
        $this->post('/laravel/access-admin/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertSessionHasErrors('email')->assertRedirect();
        $admin = $this->admin();
        $this->post('/laravel/access-admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.2fa.challenge'));
        Notification::assertSentTo($admin, AdminLoginCode::class);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'auth.login', 'actor_id' => $admin->id]);
    }

    public function test_admin_code_allows_one_resend_per_minute_and_throttles_after_successful_delivery(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $codes = app(\App\Services\Admin\AdminLoginCodeService::class);

        $codes->issue($admin);
        $codes->issue($admin);

        try {
            $codes->issue($admin);
            $this->fail('A third code within one minute should be throttled.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('Too many sign-in codes', $exception->getMessage());
        }

        Notification::assertSentToTimes($admin, AdminLoginCode::class, 2);
    }

    public function test_admin_login_code_email_template_renders(): void
    {
        $admin = $this->admin();
        $html = (new AdminLoginCode($admin->id, '012345'))->toMail($admin)->render();

        $this->assertStringContainsString('012345', (string) $html);
    }

    public function test_admin_logout_audit_keeps_the_administrator_actor(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/laravel/access-admin/logout')->assertRedirect(route('admin.login'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'auth.logout', 'actor_id' => $admin->id]);
    }

    public function test_production_owner_email_limits_the_admin_portal_to_one_account(): void
    {
        config(['app.env' => 'production', 'admin.owner_email' => 'owner@example.com']);
        $admin = $this->admin(['email' => 'other@example.com']);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get('/laravel/access-admin/dashboard')->assertForbidden();
    }

    public function test_non_local_non_testing_deployments_enforce_the_owner_email(): void
    {
        config(['app.env' => 'staging', 'admin.owner_email' => 'owner@example.com']);
        $admin = $this->admin(['email' => 'other@example.com']);

        $this->actingAs($admin, 'admin')->get('/laravel/access-admin/dashboard')->assertForbidden();
        auth('admin')->logout();
        $this->post('/laravel/access-admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    public function test_overview_uses_real_records_and_excludes_pending_test_and_failed_payments(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create(['created_at' => now()->subDays(2)]);
        $plan = DB::table('subscription_plans')->where('slug', 'monthly')->first();
        $this->payment($customer, $plan->id, 'paid', 'live', 1299);
        $this->payment($customer, $plan->id, 'pending', 'live', 9999);
        $this->payment($customer, $plan->id, 'test_paid', 'test', 4999);
        $resume = $customer->resumes()->create(['name' => 'Private test CV', 'source' => 'created', 'status' => 'complete']);
        $customer->analyses()->create(['resume_id' => $resume->id, 'job_title' => 'Analyst',
            'job_description' => 'Requirements', 'status' => 'completed', 'score' => 71, 'results' => []]);
        $customer->analyses()->create(['resume_id' => $resume->id, 'job_title' => 'Pending',
            'job_description' => 'Requirements', 'status' => 'pending', 'score' => null, 'results' => []]);
        DB::table('ai_usage_logs')->insert($this->usageLog($customer, 1_800));
        DB::table('ai_usage_logs')->insert($this->usageLog($customer, null));

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get('/laravel/access-admin/dashboard?from=2026-11-01&to=2026-11-13')->assertOk()
            ->assertSee('1 new in this period')->assertSee('US$12.99')
            ->assertSee('1</strong>', false)->assertSee('US$0.0018')
            ->assertSee('1 request(s) lack a configured price');
    }

    public function test_emailed_admin_code_is_single_use_and_invalid_codes_are_rejected(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->post('/laravel/access-admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $code = null;
        Notification::assertSentTo($admin, AdminLoginCode::class, function (AdminLoginCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->post('/laravel/access-admin/2fa/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->post('/laravel/access-admin/2fa/verify', ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->post('/laravel/access-admin/2fa/verify', ['code' => $code])->assertSessionHasErrors('code');
    }

    private function admin(array $attributes = []): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'super_admin', 'is_admin' => true, 'account_status' => 'active', ...$attributes])->save();

        return $admin;
    }

    private function payment(User $user, int $planId, string $status, string $mode, int $cents): void
    {
        BillingPayment::create(['reference' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
            'subscription_plan_id' => $planId, 'provider' => 'paynow', 'mode' => $mode, 'amount_cents' => $cents,
            'currency' => 'USD', 'billing_interval' => 'monthly', 'status' => $status, 'kind' => 'subscription',
            'credits' => 100, 'paid_at' => $status === 'paid' ? now()->subDay() : null,
            'verified_at' => $status === 'paid' ? now()->subDay() : null]);
    }

    private function usageLog(User $user, ?int $cost): array
    {
        $operationId = DB::table('ai_operations')->insertGetId(['user_id' => $user->id, 'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'feature' => 'analyse', 'request_hash' => str_repeat('a', 64), 'status' => 'completed', 'subscription_spent' => 5,
            'topup_spent' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return ['user_id' => $user->id, 'ai_operation_id' => $operationId, 'feature' => 'analyse', 'provider' => 'openai',
            'model' => 'gpt-4.1', 'input_tokens' => 100, 'cached_input_tokens' => 0, 'output_tokens' => 200,
            'credits_charged' => 5, 'status' => 'completed', 'input_cost_micros' => $cost,
            'output_cost_micros' => 0, 'total_cost_micros' => $cost, 'created_at' => now(), 'updated_at' => now()];
    }
}
