<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rating_submission_shows_success_and_dashboard_hides_feedback_history(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/feedback', [
            'category' => 'cv_analysis',
            'rating' => 5,
            'body' => 'The CV suggestions were easy to understand.',
        ])->assertRedirect()->assertSessionHas('status', 'Your rating was successfully sent. Thank you for your feedback.');

        $this->get('/dashboard')->assertOk()
            ->assertSee('Your rating was successfully sent.')
            ->assertDontSee('Your feedback history')
            ->assertDontSee('The CV suggestions were easy to understand.');
    }
}
