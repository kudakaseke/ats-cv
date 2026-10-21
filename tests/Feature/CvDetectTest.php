<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvDetectTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_requires_a_verified_account(): void
    {
        $this->get('/workspace/detect')->assertRedirect('/login');
        $this->postJson('/checker/detect', [])->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->postJson('/checker/detect', [])->assertForbidden();
    }

    public function test_detect_checks_cv_without_job_or_ai_and_does_not_charge(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->actingAs($user)->get('/workspace/detect')->assertOk()->assertSee('ATS Detect')->assertSee('No job description needed.');
        $text = "Alex Example\nalex@example.com | +27 71 123 4567\nWork Experience\nAnalyst, Example Ltd, 2020 - Present\n".str_repeat('Delivered accurate financial reports and supported monthly business reporting. ', 12)."\nEducation\nDegree, Example College, 2019\nTechnical Skills\nReporting, Excel";
        $this->postJson('/checker/detect', ['resume_text' => $text])->assertOk()
            ->assertJsonPath('passed', 7)->assertJsonPath('total', 7)
            ->assertJsonMissingPath('similarity_percent');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_operations', 0);
    }

    public function test_missing_sections_and_date_ranges_do_not_pass_as_contact_details(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/checker/detect', ['resume_text' => 'Employment from 2020 - 2024.'])
            ->assertOk()->assertJsonPath('checks.1.passed', false)->assertJsonPath('checks.2.passed', false)
            ->assertJsonPath('checks.3.passed', false)->assertJsonPath('checks.4.passed', false)->assertJsonPath('checks.5.passed', false);
        $this->postJson('/checker/detect', [])->assertUnprocessable();
        $this->postJson('/checker/detect', ['resume_text' => str_repeat('a', 20001)])->assertUnprocessable();
    }

    public function test_dashboard_starts_with_requested_tools_before_credit_summary(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['<h2>ATS Scanner</h2>', '<h2>Create CV</h2>', '<h2>View Templates</h2>', '<h2>Plan</h2>', '<h2>Settings</h2>', '<h2>ATS Detect</h2>', '<h2>Make your next move.</h2>'], false);
    }
}
