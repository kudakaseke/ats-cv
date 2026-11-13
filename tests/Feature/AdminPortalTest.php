<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\User;
use App\Services\Admin\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_customer_accounts_cannot_open_admin_pages(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($customer, 'admin')->get('/admin/dashboard')->assertForbidden();
    }

    public function test_super_admin_must_enroll_and_confirm_totp_before_dashboard_access(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertRedirect(route('admin.2fa.setup'));
        $this->get('/admin/2fa/setup')->assertOk()->assertSee('Authenticator enrollment QR code')->assertDontSee('workspace-shell');
        $secret = session('admin_totp_setup_secret');
        $code = app(TotpService::class)->code($secret, intdiv(time(), 30));
        $this->post('/admin/2fa/confirm', ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'auth.2fa_enabled', 'actor_id' => $admin->id]);
        $this->get('/admin/dashboard')->assertOk()->assertSee('Overview')->assertSee('Super Admin Dashboard')->assertDontSee('workspace-shell');
    }

    public function test_admin_login_checks_role_and_then_requires_an_authenticator_code(): void
    {
        $customer = User::factory()->create();
        $this->post('/admin/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertSessionHasErrors('email')->assertRedirect();
        $admin = $this->admin();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.2fa.setup'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'auth.login', 'actor_id' => $admin->id]);
    }

    public function test_production_owner_email_limits_the_admin_portal_to_one_account(): void
    {
        config(['app.env' => 'production', 'admin.owner_email' => 'owner@example.com']);
        $admin = $this->admin(['email' => 'other@example.com', 'two_factor_confirmed_at' => now(),
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get('/admin/dashboard')->assertForbidden();
    }

    public function test_overview_uses_real_records_and_excludes_pending_test_and_failed_payments(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(12, 0));
        $admin = $this->admin(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
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
            ->get('/admin/dashboard?from=2026-11-01&to=2026-11-13')->assertOk()
            ->assertSee('1 new in this period')->assertSee('US$12.99')
            ->assertSee('1</strong>', false)->assertSee('US$0.0018')
            ->assertSee('1 request(s) lack a configured price');
    }

    public function test_totp_codes_are_single_use_and_time_based(): void
    {
        $service = app(TotpService::class);
        $secret = $service->secret();
        $counter = intdiv(time(), 30);
        $code = $service->code($secret, $counter);
        $this->assertSame($counter, $service->matchingCounter($secret, $code, null, $counter * 30));
        $this->assertNull($service->matchingCounter($secret, $code, $counter, $counter * 30));
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
