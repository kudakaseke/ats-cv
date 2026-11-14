<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
