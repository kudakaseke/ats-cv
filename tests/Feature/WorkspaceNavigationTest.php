<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_discover_history_and_activity_but_not_admin_billing(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()
            ->assertSee(route('workspace.module', 'history'))
            ->assertSee(route('account.activity'))->assertDontSee('Billing management');
        $this->get('/admin/billing')->assertForbidden();
    }

    public function test_administrators_can_discover_billing_management(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'role' => 'super_admin', 'account_status' => 'active',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->get('/profile')->assertOk()->assertDontSee('Billing management');
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get(route('admin.billing'))->assertOk()->assertSee('Paynow billing management')->assertDontSee('workspace-shell');
    }
}
