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

    public function test_customer_login_does_not_reuse_an_admin_destination_as_intended_url(): void
    {
        $this->get('/laravel/access-admin/dashboard')
            ->assertRedirect('/laravel/access-admin/login')
            ->assertSessionMissing('url.intended');

        $customer = User::factory()->create();
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($customer);
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
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'auth.email_code_verified', 'actor_id' => $admin->id, 'actor_role' => 'super_admin']);
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

    public function test_admin_can_find_a_customer_and_view_only_their_payment_history(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create(['created_at' => now()->subMonths(2)]);
        $other = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        $this->payment($customer, $planId, 'paid', 'live', 1200);
        $this->payment($customer, $planId, 'pending', 'live', 3400);
        $this->payment($other, $planId, 'paid', 'live', 5600);
        $otherReference = DB::table('billing_payments')->where('user_id', $other->id)->value('reference');

        $this->get(route('admin.users.payments', $customer->id))->assertRedirect(route('admin.login'));
        $this->actingAs($customer)->get(route('admin.users.payments', $customer->id))->assertForbidden();
        auth('web')->logout();
        $this->actingAs($admin, 'admin')->get(route('admin.users.payments', $customer->id))
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->get(route('admin.section', 'accounts'))->assertOk()->assertSee($customer->email)
            ->assertSee('>View</a>', false)->assertSee(route('admin.users.show', $customer->id));
        $this->get(route('admin.section', ['section' => 'accounts', 'search' => $customer->email]))
            ->assertOk()->assertSee($customer->email)->assertSee(route('admin.users.show', $customer->id));
        $this->get(route('admin.users.payments', $customer->id))->assertOk()
            ->assertSee('USD 12.00')->assertSee('USD 34.00')->assertSee('16 Nov 2026 12:00')
            ->assertSee('Pending')->assertDontSee($otherReference);
        $this->get(route('admin.users.payments', $admin->id))->assertNotFound();
    }

    public function test_admin_credit_grant_is_audited_and_idempotent(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        $this->payment($customer, $planId, 'paid', 'live', 1200);
        $this->payment($other, $planId, 'paid', 'live', 3400);
        DB::table('billing_payments')->where('user_id', $customer->id)->update(['kind' => 'topup', 'credits' => 30]);
        $payment = DB::table('billing_payments')->where('user_id', $customer->id)->value('reference');
        $otherPayment = DB::table('billing_payments')->where('user_id', $other->id)->value('reference');
        $data = ['credits' => 30, 'reason' => 'Manual payment correction', 'payment_reference' => $payment,
            'request_id' => (string) \Illuminate\Support\Str::uuid()];
        $url = route('admin.users.credits', $customer->id);
        $this->post($url, $data)->assertRedirect(route('admin.login'));
        $this->actingAs($customer)->post($url, $data)->assertForbidden();
        auth('web')->logout();
        $this->actingAs($admin, 'admin')->post($url, $data)->assertRedirect(route('admin.2fa.challenge'));
        $this->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->from(route('admin.users.payments', $customer->id))->post($url, [...$data, 'payment_reference' => $otherPayment])
            ->assertSessionHasErrors('payment_reference');
        $this->post($url, $data)->assertRedirect(route('admin.users.payments', $customer->id));
        $this->post($url, $data)->assertRedirect()->assertSessionHas('status', 'This credit grant was already recorded.');
        $this->assertDatabaseHas('ai_wallets', ['user_id' => $customer->id, 'topup_credits' => 30]);
        $this->assertDatabaseHas('ai_credit_transactions', ['user_id' => $customer->id, 'type' => 'admin_grant', 'topup_delta' => 30, 'reference' => 'payment:'.$payment]);
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'credits.admin_grant')->where('subject_id', $customer->id)->count());
        $this->get(route('admin.users.payments', $customer->id))->assertOk()->assertSee('Manual payment correction')->assertSee($payment);
    }

    public function test_user_profile_tabs_are_private_and_show_the_selected_customers_records(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['name' => 'Profile Customer']);
        $other = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        $this->payment($customer, $planId, 'paid', 'live', 1200);
        $this->payment($other, $planId, 'paid', 'live', 8800);
        $this->get(route('admin.users.show', $customer->id))->assertRedirect(route('admin.login'));
        $this->actingAs($customer)->get(route('admin.users.show', $other->id))->assertForbidden();
        auth('web')->logout();
        $this->actingAs($admin, 'admin')->get(route('admin.users.show', $customer->id))->assertRedirect(route('admin.2fa.challenge'));
        $this->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        foreach (['profile', 'subscription', 'payments', 'usage', 'credits', 'reviews', 'security', 'audit'] as $tab) {
            $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => $tab]))->assertOk()->assertSee('Profile Customer');
        }
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'payments']))
            ->assertSee('USD 12.00')->assertDontSee('USD 88.00');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'invalid']))->assertNotFound();
        $this->get(route('admin.users.show', $admin->id))->assertNotFound();
    }

    public function test_profile_tabs_show_only_the_selected_users_usage_feedback_and_credits(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $other = User::factory()->create();
        DB::table('ai_usage_logs')->insert($this->usageLog($customer, 70_000));
        DB::table('ai_usage_logs')->insert($this->usageLog($other, 200_000));
        foreach ([[$customer, 'My selected user complaint'], [$other, 'Other private user complaint']] as [$user, $body]) {
            DB::table('customer_feedback')->insert(['user_id' => $user->id, 'category' => 'complaint', 'body' => $body,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }
        app(\App\Services\AiCreditService::class)->topup($customer, 17, 'customer-topup-check');
        app(\App\Services\AiCreditService::class)->topup($other, 33, 'other-topup-private');
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'usage']))
            ->assertOk()->assertSee('analyse')->assertSee('completed');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'reviews']))
            ->assertOk()->assertSee('My selected user complaint')->assertDontSee('Other private user complaint');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'credits']))
            ->assertOk()->assertSee('customer-topup-check')->assertDontSee('other-topup-private');
    }

    public function test_profile_payment_and_credit_pagination_preserves_the_correct_tab(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        for ($i = 0; $i < 26; $i++) {
            $this->payment($customer, $planId, 'paid', 'live', 1200);
            app(\App\Services\AiCreditService::class)->topup($customer, 1, 'pagination-topup-'.$i);
        }
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'payments', 'page' => 2]))
            ->assertOk()->assertSee('USD 12.00')->assertSee('tab=payments', false);
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'credits', 'credits_page' => 2]))
            ->assertOk()->assertSee('tab=credits', false)->assertSee('pagination-topup-0');
    }

    public function test_user_list_export_applies_filters_and_escapes_spreadsheet_formulas(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['name' => '=Formula Test', 'account_status' => 'active']);
        User::factory()->create(['name' => 'Suspended Customer', 'account_status' => 'suspended']);
        User::factory()->create(['name' => '  =Hidden Formula', 'account_status' => 'active']);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $response = $this->get(route('admin.section', ['section' => 'accounts', 'account_status' => 'active', 'export' => 'csv']));
        $response->assertOk()->assertDownload('users.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=Formula Test", $csv);
        $this->assertStringContainsString("'  =Hidden Formula", $csv);
        $this->assertStringContainsString($customer->email, $csv);
        $this->assertStringNotContainsString('Suspended Customer', $csv);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'users.exported']);
    }

    public function test_profile_credit_grant_returns_to_credits_and_records_change(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->post(route('admin.users.credits', $customer->id), [
                'credits' => 20, 'reason' => 'Customer credit correction',
                'request_id' => (string) \Illuminate\Support\Str::uuid(), 'return_to_profile' => '1',
            ])->assertRedirect(route('admin.users.show', ['user' => $customer->id, 'tab' => 'credits']));
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'credits']))
            ->assertOk()->assertSee('Customer credit correction');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'audit']))
            ->assertOk()->assertSee('Customer credit correction');
    }

    public function test_local_read_only_preview_cannot_export_user_data_without_verification(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.section', ['section' => 'accounts', 'export' => 'csv']))
            ->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_suspended_customer_with_an_existing_session_cannot_access_the_dashboard(): void
    {
        $customer = User::factory()->create(['account_status' => 'suspended']);
        $this->actingAs($customer)->withSession(['password_hash_web' => auth('web')->hashPasswordForCookie($customer->getAuthPassword())])
            ->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_suspend_and_restore_show_the_right_result_and_record_the_reason(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $profile = route('admin.users.show', $customer->id);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->from($profile)->post(route('admin.users.action', $customer->id), ['action' => 'suspend', 'reason' => 'Account review requested'])
            ->assertRedirect($profile)->assertSessionHas('status', 'User account suspended. Change recorded against your super-admin account.');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'audit']))->assertOk()->assertSee('Account review requested');
        $this->from($profile)->post(route('admin.users.action', $customer->id), ['action' => 'restore'])
            ->assertRedirect($profile)->assertSessionHas('status', 'User account restored. Change recorded against your super-admin account.');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'account_status' => 'active']);
    }

    public function test_admin_is_not_told_cookie_sessions_were_revoked_when_they_cannot_be(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        DB::table('account_sessions')->insert(['id' => 'cookie-session-check', 'user_id' => $customer->id, 'driver' => 'cookie', 'last_activity' => time()]);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->from(route('admin.users.show', ['user' => $customer->id, 'tab' => 'security']))
            ->post(route('admin.users.sessions.revoke', $customer->id), ['reason' => 'Customer requested sign out'])
            ->assertSessionHasErrors('sessions');
        $this->assertDatabaseHas('account_sessions', ['id' => 'cookie-session-check']);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'user.sessions_revoked', 'subject_id' => (string) $customer->id]);
    }

    public function test_admin_can_revoke_customer_sessions_and_audit_the_reason(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        DB::table('account_sessions')->insert(['id' => 'user-profile-session', 'user_id' => $customer->id, 'driver' => 'array', 'last_activity' => time()]);
        $url = route('admin.users.sessions.revoke', $customer->id);
        $this->actingAs($admin, 'admin')->post($url, ['reason' => 'Customer requested sign out'])->assertRedirect(route('admin.2fa.challenge'));
        $this->withSession(['admin_2fa_verified_at' => now()->timestamp])->post($url, ['reason' => 'Customer requested sign out'])
            ->assertRedirect(route('admin.users.show', ['user' => $customer->id, 'tab' => 'security']));
        $this->assertDatabaseMissing('account_sessions', ['id' => 'user-profile-session']);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'user.sessions_revoked', 'subject_id' => (string) $customer->id]);
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'audit']))->assertOk()->assertSee('Customer requested sign out');
    }

    public function test_billing_revenue_counts_only_verified_live_usd_payments(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        $this->payment($customer, $planId, 'paid', 'live', 1200);
        $this->payment($customer, $planId, 'paid', 'live', 3400);
        $this->payment($customer, $planId, 'paid', 'live', 5600);
        $this->payment($customer, $planId, 'test_paid', 'test', 7800);
        DB::table('billing_payments')->where('amount_cents', 3400)->update(['verified_at' => null]);
        DB::table('billing_payments')->where('amount_cents', 5600)->update(['currency' => 'ZAR']);

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get(route('admin.billing'))->assertOk()
            ->assertViewHas('monthlyRevenue', 1200)->assertViewHas('annualRevenue', 1200)
            ->assertViewHas('plans', fn ($plans) => $plans->sum('cents') == 1200)
            ->assertSee('ZAR 56.00');
    }

    public function test_admin_charts_reject_ranges_longer_than_a_year(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->get(route('admin.dashboard', ['from' => '2024-01-01', 'to' => '2026-01-01']))->assertStatus(422);
        $this->get(route('admin.section', ['section' => 'users', 'from' => '2024-01-01', 'to' => '2026-01-01']))->assertStatus(422);
    }

    public function test_overview_calendar_defaults_to_today(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.dashboard').'"', false)
            ->assertSee('>Today</a>', false)
            ->assertViewHas('period', fn (array $period) => $period['from'] === '2026-11-17' && $period['to'] === '2026-11-17');
    }

    public function test_overview_and_revenue_count_only_current_customer_subscriptions(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $admin = $this->admin();
        $customer = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');

        $customer->subscriptions()->create(['subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $customer->subscriptions()->create(['subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->subSecond()]);
        $customer->subscriptions()->create(['subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addMonth()]);
        $admin->subscriptions()->create(['subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['active_subscriptions'] === 1);

        $this->get(route('admin.section', 'revenue'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['Currently active subscriptions'] === 1);
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

    public function test_payment_corrections_reject_unverified_and_duplicate_allocations(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        $this->payment($customer, $planId, 'pending', 'live', 1200);
        $payment = DB::table('billing_payments')->where('user_id', $customer->id)->first();
        DB::table('billing_payments')->where('id', $payment->id)->update(['kind' => 'topup', 'credits' => 30]);
        $data = ['credits' => 30, 'reason' => 'Correct missing payment credits', 'payment_reference' => $payment->reference, 'request_id' => (string) \Illuminate\Support\Str::uuid()];
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->post(route('admin.users.credits', $customer->id), $data)->assertSessionHasErrors('payment_reference');
        DB::table('billing_payments')->where('id', $payment->id)->update(['status' => 'paid', 'paid_at' => now(), 'verified_at' => now()]);
        $this->post(route('admin.users.credits', $customer->id), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.users.credits', $customer->id), [...$data, 'request_id' => (string) \Illuminate\Support\Str::uuid()])->assertSessionHasErrors('payment_reference');
        $this->assertDatabaseHas('ai_wallets', ['user_id' => $customer->id, 'topup_credits' => 30]);
        app(\App\Services\AiCreditService::class)->reverseTopup($customer, 30, 'reverse:'.$payment->reference);
        $this->assertDatabaseHas('ai_wallets', ['user_id' => $customer->id, 'topup_credits' => 0]);
    }

    public function test_profile_views_filters_and_export_are_audited(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'security']))->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.profile_viewed', 'subject_type' => 'User', 'subject_id' => (string) $customer->id]);
        $this->get(route('admin.section', ['section' => 'accounts', 'account_status' => 'active']))->assertOk()->assertSee('name="account_status" value="active"', false)->assertSee('Free');
        $this->get(route('admin.section', ['section' => 'accounts', 'account_status' => 'active', 'export' => 'csv']))->assertOk();
        $export = DB::table('admin_audit_logs')->where('action', 'users.exported')->latest('id')->first();
        $this->assertSame('active', json_decode($export->new_values, true)['account_status']);
    }

    public function test_suspension_rolls_back_when_audit_recording_fails(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $trail = \Mockery::mock(\App\Services\Admin\AdminAuditTrail::class)->makePartial();
        $trail->shouldReceive('record')->withArgs(fn ($action) => $action === 'user.suspend')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(\App\Services\Admin\AdminAuditTrail::class, $trail);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->post(route('admin.users.action', $customer->id), ['action' => 'suspend', 'reason' => 'Account investigation'])->assertStatus(500);
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'account_status' => 'active', 'session_version' => 0]);
    }

    public function test_revoked_session_version_cannot_be_reused(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->withSession(['password_hash_web' => auth('web')->hashPasswordForCookie($customer->getAuthPassword()), 'account_session_version' => 0]);
        DB::table('users')->where('id', $customer->id)->update(['session_version' => 1]);
        auth('web')->forgetUser();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_profile_shows_spendable_balance_and_paginates_payment_reviews(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        DB::table('ai_wallets')->where('user_id', $customer->id)->update(['subscription_credits' => 100, 'allocation_reference' => 'subscription:1:expired', 'expires_at' => now()->subDay()]);
        $planId = DB::table('subscription_plans')->where('slug', 'monthly')->value('id');
        $this->payment($customer, $planId, 'paid', 'live', 1200);
        $paymentId = DB::table('billing_payments')->where('user_id', $customer->id)->value('id');
        for ($i = 0; $i < 30; $i++) {
            DB::table('billing_reviews')->insert(['billing_payment_id' => $paymentId, 'admin_id' => $admin->id, 'type' => 'refund', 'notes' => 'Review '.$i, 'created_at' => now()->subMinutes(30 - $i), 'updated_at' => now()]);
        }
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp]);
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'credits']))->assertOk()->assertSee('Available balance: 0');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'payments']))->assertOk()->assertSee('Review 29')->assertDontSee('Review 0');
        $this->get(route('admin.users.show', ['user' => $customer->id, 'tab' => 'payments', 'reviews_page' => 2]))->assertOk()->assertSee('Review 0');
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
