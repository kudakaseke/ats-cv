<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_preview_can_open_every_admin_section_and_each_has_real_section_description(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $admin = $this->admin();
        foreach (['users' => 'User Analytics', 'revenue' => 'Subscriptions & Revenue', 'cv' => 'CV Analytics', 'ai' => 'AI Usage & Costs', 'credits' => 'Credit Analytics', 'accounts' => 'Users Management', 'reviews' => 'Reviews & Feedback', 'system' => 'System Monitoring'] as $slug => $title) {
            $this->actingAs($admin, 'admin')->get(route('admin.section', $slug))->assertOk()->assertSee($title);
        }
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.request']);
    }

    public function test_super_admin_sidebar_uses_registered_icons_for_every_navigation_section(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $this->actingAs($this->admin(), 'admin')->get('/admin/dashboard')->assertOk()
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

    public function test_new_customer_registration_and_ats_scan_are_visible_in_super_admin_analytics(): void
    {
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

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertSee('1 new in this period');
        $this->get(route('admin.section', 'users'))->assertOk()->assertSee('audit.customer@example.test');
        $this->get(route('admin.section', 'accounts'))->assertOk()->assertSee('audit.customer@example.test');
        $this->get(route('admin.section', 'cv'))->assertOk()->assertSee('audit.customer@example.test')->assertSee('ATS score');
        $this->get(route('admin.section', 'ai'))->assertOk()->assertSee('AI requests')->assertSee('gpt-4.1');
        $this->get(route('admin.section', 'credits'))->assertOk()->assertSee('Credits consumed (recorded)');
    }

    public function test_customer_feedback_submission_and_moderation_are_linked_to_the_admin(): void
    {
        config(['app.env' => 'local', 'admin.dashboard_preview_without_2fa' => true]);
        $customer = User::factory()->create();
        $admin = $this->admin(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($customer)->post('/feedback', ['category' => 'suggestion', 'rating' => 5, 'body' => 'Please add another export format.', 'testimonial_consent' => 1])->assertRedirect();
        $feedback = \Illuminate\Support\Facades\DB::table('customer_feedback')->first();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->post(route('admin.feedback.moderate', $feedback->id), ['status' => 'approved', 'admin_reply' => 'Thank you.'])->assertRedirect();
        $this->assertDatabaseHas('customer_feedback', ['id' => $feedback->id, 'moderated_by' => $admin->id, 'status' => 'approved']);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'feedback.moderated', 'subject_id' => (string) $feedback->id]);
    }

    public function test_suspending_user_revokes_active_sessions_and_audits_admin_actor(): void
    {
        $admin = $this->admin(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
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
