<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_homepage_has_navigation_and_database_pricing(): void
    {
        SubscriptionPlan::where('slug', 'basic')->update(['price_cents' => 500]);
        $this->get('/')->assertOk()->assertSee('Make Your CV')->assertSee('Sign In')
            ->assertSee('Sign Up')->assertSee('$5')->assertSee('AI ATS')->assertSee('Your Journey');
    }

    public function test_guests_and_unverified_users_cannot_access_workspace(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/workspace/checker')->assertRedirect('/login');
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/verify-email');
    }

    public function test_dashboard_is_scoped_to_the_current_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownResume = $owner->resumes()->create(['name' => 'My personal CV']);
        $otherResume = $other->resumes()->create(['name' => 'Private other CV']);
        $owner->analyses()->create(['resume_id' => $ownResume->id, 'job_description' => 'Own job', 'status' => 'completed', 'score' => 72]);
        $other->analyses()->create(['resume_id' => $otherResume->id, 'job_description' => 'Other job', 'status' => 'completed', 'score' => 99]);
        Livewire::actingAs($owner)->test(Dashboard::class)->assertSee('My personal CV')
            ->assertDontSee('Private other CV')->assertViewHas('resumeCount', 1)
            ->assertViewHas('analysisCount', 1)->assertViewHas('latestScore', 72)->assertViewHas('remaining', 2);
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('My personal CV')->assertDontSee('Private other CV');
    }

    public function test_new_accounts_are_free_and_viewing_plans_never_activates_payment(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/subscription')->assertOk()->assertSee('No live payments');
        $this->assertSame('free', app(PlanService::class)->current($user)->slug);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_pending_or_expired_paid_plans_do_not_grant_paid_access(): void
    {
        $user = User::factory()->create();
        $pro = SubscriptionPlan::where('slug', 'pro')->first();
        $user->subscriptions()->create(['subscription_plan_id' => $pro->id, 'status' => 'pending']);
        $user->subscriptions()->create(['subscription_plan_id' => $pro->id, 'status' => 'active', 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay()]);
        $this->assertSame('free', app(PlanService::class)->current($user)->slug);
    }

    public function test_usage_resets_monthly_and_failed_analyses_do_not_consume_allowance(): void
    {
        $user = User::factory()->create();
        $resume = $user->resumes()->create(['name' => 'CV']);
        $oldAnalysis = $user->analyses()->create(['resume_id' => $resume->id, 'job_description' => 'Old job', 'status' => 'completed', 'score' => 50]);
        $oldAnalysis->forceFill(['created_at' => now()->subMonth()->startOfMonth()])->save();
        $user->analyses()->create(['resume_id' => $resume->id, 'job_description' => 'Failed job', 'status' => 'failed']);
        $user->analyses()->create(['resume_id' => $resume->id, 'job_description' => 'Queued job', 'status' => 'pending']);
        $this->assertSame(2, app(PlanService::class)->remainingAnalyses($user));
    }

    public function test_account_deletion_cascades_workspace_records(): void
    {
        $user = User::factory()->create();
        $resume = $user->resumes()->create(['name' => 'CV']);
        $user->analyses()->create(['resume_id' => $resume->id, 'job_description' => 'Job']);
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertDatabaseCount('resumes', 0);
        $this->assertDatabaseCount('cv_analyses', 0);
    }

    public function test_legal_and_workspace_pages_render_and_unknown_modules_return_404(): void
    {
        foreach (['privacy', 'terms', 'contact'] as $page) {
            $this->get('/'.$page)->assertOk();
        }
        $this->actingAs(User::factory()->create());
        foreach (['checker', 'revamp', 'builder', 'resumes', 'history'] as $module) {
            $this->get('/workspace/'.$module)->assertOk()->assertSee('under development');
        }
        $this->get('/workspace/unknown')->assertNotFound();
    }
}
