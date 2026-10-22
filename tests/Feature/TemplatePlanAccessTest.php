<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplatePlanAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_free_users_have_seven_templates_and_cannot_save_a_paid_design(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertCount(7, app(PlanService::class)->templateIds(null));
        $user = User::factory()->create();
        $this->assertCount(7, app(PlanService::class)->templateIds($user));
        $data = ['template' => 'nordic', 'name' => 'Alex Example', 'experience' => [], 'education' => [], 'achievements' => [], 'courses' => [], 'languages' => []];
        $this->postJson('/get-started/draft', ['step' => 4, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data.template');
        $this->actingAs($user)->postJson('/get-started/draft', ['step' => 4, 'data' => $data])->assertUnprocessable();
        $data['template'] = 'essential';
        $this->postJson('/get-started/draft', ['step' => 4, 'data' => $data])->assertOk();
    }

    public function test_paid_subscription_unlocks_all_templates_and_existing_saved_designs_survive_expiry(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assertCount(count(config('cv_templates')), app(PlanService::class)->templateIds($user));
        $user->resumes()->create(['name' => 'Existing CV', 'source' => 'created', 'status' => 'draft', 'content' => ['template' => 'nordic']]);
        $subscription->update(['ends_at' => now()->subMinute()]);
        $this->assertContains('nordic', app(PlanService::class)->templateIds($user));
        $this->assertNotContains('studio', app(PlanService::class)->templateIds($user));
    }
}
