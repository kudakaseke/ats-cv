<?php

namespace Tests\Unit;

use App\Services\CvExperienceMatcher;
use Tests\TestCase;

class CvExperienceMatcherTest extends TestCase
{
    public function test_multiline_year_requirements_and_unreadable_cv_dates_remain_explicit(): void
    {
        $result = app(CvExperienceMatcher::class)->compare("Required experience:\n1 month\nRelevant experience:\n1 year", "Experience\nPosition: Manager\nPeriod: 00/2000 - Present");
        $this->assertEquals(1, $result['required_years']);
        $this->assertNull($result['cv_years']);
        $this->assertContains('00/2000 - Present', $result['unreadable_dates']);
        $this->assertStringContainsString('1 year of experience', $result['message']);
    }

    public function test_five_required_years_and_two_year_cv_show_shortfall(): void
    {
        $result = app(CvExperienceMatcher::class)->compare('At least five years of underwriting experience required.', "Alex Example\nWork Experience\nPosition: Analyst\nCompany: Example\nPeriod: Jan 2023 - Dec 2024\nEducation\nDegree: Commerce\nPeriod: 2018 - 2022");
        $this->assertEquals(5, $result['required_years']);
        $this->assertEquals(2, $result['cv_years']);
        $this->assertTrue($result['shortfall']);
        $this->assertStringContainsString('fewer years', $result['message']);
    }

    public function test_overlapping_jobs_count_once_and_future_end_dates_are_capped(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20));
        $result = app(CvExperienceMatcher::class)->compare('5+ years of experience.', "Alex Example\nExperience\nPosition: Analyst\nPeriod: Jan 2024 - Dec 2025\n\nPosition: Manager\nPeriod: Jan 2025 - Dec 2030");
        $this->assertEquals(2.8, $result['cv_years']);
        $this->travelBack();
    }

    public function test_unknown_experience_is_not_claimed_to_be_zero(): void
    {
        $result = app(CvExperienceMatcher::class)->compare('Minimum 5 years experience required.', "Alex Example\nEducation\nBachelor of Commerce 2018 - 2022");
        $this->assertNull($result['cv_years']);
        $this->assertFalse($result['shortfall']);
        $this->assertStringContainsString('could not estimate', $result['message']);
    }

    public function test_explicit_cv_experience_claims_and_missing_job_requirements(): void
    {
        $result = app(CvExperienceMatcher::class)->compare('3 years experience required.', 'Professional with two years of experience in finance.');
        $this->assertEquals(2, $result['cv_years']);
        $this->assertTrue($result['shortfall']);
        $this->assertNull(app(CvExperienceMatcher::class)->compare('PHP developer', 'CV text')['required_years']);
    }
}
