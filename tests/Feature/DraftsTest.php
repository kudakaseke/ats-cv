<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_are_private_and_only_list_owned_unfinished_cvs(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = $owner->resumes()->create(['name' => 'My unfinished CV', 'status' => 'draft', 'source' => 'created', 'content' => []]);
        $other->resumes()->create(['name' => 'Private other CV', 'status' => 'draft', 'source' => 'created', 'content' => []]);
        $this->get('/workspace/drafts')->assertRedirect(route('login'));
        $this->actingAs($owner)->get('/workspace/drafts')->assertOk()->assertSee('My unfinished CV')
            ->assertSee(route('builder.edit', $draft))->assertDontSee('Private other CV');
    }

    public function test_empty_state_and_current_browser_draft(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->actingAs(User::factory()->create())->get('/workspace/drafts')->assertOk()->assertSee('No drafts yet');
        $this->withSession(['cv_builder' => ['step' => 2, 'data' => ['name' => 'Current work']]])
            ->get('/workspace/drafts')->assertOk()->assertSee('Current work CV')->assertSee('Continue draft')->assertDontSee('No drafts yet');
    }
}
