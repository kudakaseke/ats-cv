<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CvReviewComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CvReviewWarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_generated_draft_repairs_an_unsupported_vacancy_headline(): void
    {
        $source = "Alex Example\nAccountant\nSkills\nReporting";
        $revised = "Alex Example\nProcurement Officer\nSkills\nReporting";
        $comparison = app(CvReviewComparison::class)->make('Procurement Officer', $source, $revised);
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => ['step' => 4, 'data' => ['name' => 'Alex Example', 'role' => 'Procurement Officer'], 'checker_result' => $comparison]])
            ->get('/get-started')->assertOk()->assertSessionHas('cv_builder.data.role', 'Accountant');
        $this->assertStringNotContainsString('Procurement Officer', session('cv_builder.checker_result.revised_text'));
    }

    public function test_existing_draft_does_not_replace_a_manually_edited_headline(): void
    {
        $source = "Alex Example\nAccountant\nSkills\nReporting";
        $comparison = app(CvReviewComparison::class)->make('Procurement Officer', $source, "Alex Example\nProcurement Officer\nSkills\nReporting");
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => ['step' => 4, 'data' => ['name' => 'Alex Example', 'role' => 'Finance Professional'], 'checker_result' => $comparison]])
            ->get('/get-started')->assertOk()->assertSessionHas('cv_builder.data.role', 'Finance Professional');
    }

    public function test_review_has_grouped_lists_and_red_experience_warning_and_refreshes_old_scores(): void
    {
        $job = "Qualifications\nBachelor of Commerce\nSkills\nSQL\nCompetencies\nTeamwork\nExperience\n5 years of experience";
        $cv = "Alex Example\nExperience\nPosition: Analyst\nPeriod: Jan 2023 - Dec 2024\nEducation\nDiploma in Computing\nSkills\nPython";
        $comparison = ['job_description' => $job, 'original_text' => $cv, 'revised_text' => $cv, 'keyword_before' => 8, 'keyword_after' => 8];
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => ['step' => 4, 'data' => ['name' => 'Alex Example'], 'checker_result' => $comparison]])
            ->get('/get-started')->assertOk()->assertSee('Requirements still missing (3)')->assertSee('Warnings')
            ->assertSee('checker-review-warnings', false)->assertSee('approximately 2 years')->assertSee('<li>Bachelor of Commerce</li>', false)
            ->assertSee('<li>SQL</li>', false)->assertSee('<li>Teamwork</li>', false)
            ->assertSessionHas('cv_builder.checker_result.comparison_version', CvReviewComparison::VERSION)
            ->assertSessionHas('cv_builder.checker_result.keyword_after', 0);
    }
}
