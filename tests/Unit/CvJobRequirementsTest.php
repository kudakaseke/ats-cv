<?php

namespace Tests\Unit;

use App\Services\CvKeywordMatcher;
use PHPUnit\Framework\TestCase;

class CvJobRequirementsTest extends TestCase
{
    public function test_long_advert_prose_does_not_create_comma_fragments_or_miss_social_sciences(): void
    {
        $job = "Required education level:\nBachelor's degree in Nutrition, Public Health, Social Sciences, Health promotion\nCompetencies and values:\nAccountability\nSkills and experience:\nBackground in Nutrition, Public Health, or a related field; experience in youth engagement is an asset.\nExcellent oral and written communication skills, including drafting, formulation, and reporting abilities.\nComputer literacy, including proficiency in Microsoft Office applications (Word, Excel, etc.), email, and internet use; familiarity with database management and office technology.\nStudy Diplomacy\nArea(s) of expertise:\nCommunication, Nutrition\nDriving license:";
        $cv = "Education\nBachelor of Science in Psychology and Sociology\nExperience\nDeveloped training manuals for community programmes.\nTrained 12 organisations in financial accountability.\nSkills\nReporting";
        $result = (new CvKeywordMatcher)->compare($job, $cv);
        $this->assertTrue($result['requirement_groups']['qualifications'][0]['matched']);
        $this->assertContains('Oral communication', $result['matched']);
        $this->assertContains('Written communication', $result['matched']);
        $this->assertContains('Document drafting', $result['matched']);
        foreach (['including drafting', 'formulation', 'Study Diplomacy', 'Area(s) of expertise', 'Driving license', 'experience in youth engagement is an asset'] as $noise) {
            $this->assertNotContains($noise, [...$result['matched'], ...$result['missing']]);
        }
        $this->assertContains('Computer literacy', $result['missing']);
    }

    public function test_requirements_are_whole_phrases_grouped_by_category_and_filler_does_not_count(): void
    {
        $job = "Qualifications\nBachelor of Commerce\nSkills\nIdentifying Sales Opportunities, Oral Communications\nCompetencies\nTeamwork; Attention to Detail\nBenefits\nBetter can it and free lunch";
        $cv = "Alex Example\nEducation\nBachelor of Commerce\nSkills\nIdentified sales opportunities; verbal communication\nCompetencies\nTeamwork; Attention to Detail";
        $result = (new CvKeywordMatcher)->compare($job, $cv);
        $this->assertSame(5, $result['total']);
        $this->assertSame(100, $result['keyword_score']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(['qualifications', 'skills', 'competencies'], array_keys($result['requirement_groups']));
        $this->assertContains('Identifying Sales Opportunities', $result['matched']);
        $this->assertContains('Oral Communications', $result['matched']);
    }

    public function test_a_partial_skill_phrase_and_missing_degree_are_not_marked_as_met(): void
    {
        $result = (new CvKeywordMatcher)->compare("Qualifications\nBachelor of Commerce\nSkills\nIdentifying Sales Opportunities, Oral Communications", "Alex Example\nEducation\nDiploma in Computing\nSkills\nSales, communication");
        $this->assertSame(0, $result['keyword_score']);
        $this->assertSame(['Identifying Sales Opportunities', 'Oral Communications'], $result['confirmable_skills']);
        $this->assertNotContains('Bachelor of Commerce', $result['confirmable_skills']);
    }

    public function test_negated_qualifications_are_not_matched(): void
    {
        $result = (new CvKeywordMatcher)->compare("Qualifications\nBachelor of Commerce", "Alex Example\nEducation\nNo Bachelor of Commerce");
        $this->assertSame(0, $result['keyword_score']);
    }

    public function test_alternative_skills_do_not_require_both_options(): void
    {
        $result = (new CvKeywordMatcher)->compare("Skills\nPython or Java", 'Skills: Java');
        $this->assertSame(1, $result['total']);
        $this->assertSame(100, $result['keyword_score']);
    }

    public function test_alternative_degree_subjects_keep_the_qualification_level(): void
    {
        $job = "Qualifications\nBachelor degree in Commerce or Finance";
        $this->assertSame(100, (new CvKeywordMatcher)->compare($job, "Education\nBachelor of Finance")['keyword_score']);
        $this->assertSame(0, (new CvKeywordMatcher)->compare($job, "Education\nCertificate in Finance")['keyword_score']);
    }

    public function test_combined_and_generic_headings_do_not_discard_qualifications(): void
    {
        foreach (['Requirements', 'Qualifications and Experience'] as $heading) {
            $result = (new CvKeywordMatcher)->compare($heading."\nBachelor of Commerce\nIdentifying Sales Opportunities\nTeamwork\n5 years of sales experience", "Alex Example\nEducation\nDiploma in Computing\nSkills\nIdentifying Sales Opportunities\nCompetencies\nTeamwork");
            $this->assertSame(3, $result['total']);
            $this->assertSame(67, $result['keyword_score']);
            $this->assertSame(['Bachelor of Commerce'], $result['missing']);
        }
    }

    public function test_grade_levels_and_preferred_extras_do_not_create_false_matches_or_penalties(): void
    {
        $job = "Qualifications\nGrade 12\nPreferred skills\nPython";
        $result = (new CvKeywordMatcher)->compare($job, "Education\nGrade 10");
        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['keyword_score']);
        $this->assertSame(100, (new CvKeywordMatcher)->compare($job, "Education\nGrade 12")['keyword_score']);
    }

    public function test_certifications_are_checked_alongside_education(): void
    {
        $result = (new CvKeywordMatcher)->compare("Qualifications\nCertified Public Accountant", "Alex Example\nEducation\nBachelor of Commerce\nCertifications\nCertified Public Accountant");
        $this->assertSame(100, $result['keyword_score']);
    }

    public function test_higher_relevant_degrees_meet_diploma_levels_but_not_unrelated_subjects(): void
    {
        foreach (['Diploma', 'National Diploma'] as $level) {
            $job = "Qualifications\n{$level} in Accounting";
            $result = (new CvKeywordMatcher)->compare($job, "Education\nBachelor of Accounting");
            $this->assertSame(100, $result['keyword_score']);
            $this->assertCount(1, $result['green_flags']);
            $this->assertSame(0, (new CvKeywordMatcher)->compare($job, "Education\nBachelor of Computing")['keyword_score']);
        }
    }

    public function test_relevant_additional_certificates_add_explicit_bonus_without_hiding_gaps(): void
    {
        $job = "Qualifications\nDiploma in Accounting\nSkills\nExcel, Python";
        $cv = "Education\nBachelor of Accounting\nSkills\nExcel\nCertifications\nExcel Certificate - Example Institute - 2024\nExcel Certificate\nCooking Certificate";
        $result = (new CvKeywordMatcher)->compare($job, $cv);
        $this->assertSame(67, $result['base_requirement_score']);
        $this->assertSame(2, $result['certificate_bonus']);
        $this->assertSame(69, $result['keyword_score']);
        $this->assertSame(['Python'], $result['missing']);
    }

    public function test_named_mandatory_certificates_are_not_replaced_by_a_degree(): void
    {
        $result = (new CvKeywordMatcher)->compare("Qualifications\nCertified Public Accountant", "Education\nBachelor of Accounting");
        $this->assertSame(0, $result['keyword_score']);
        $this->assertSame(['Certified Public Accountant'], $result['missing']);
    }
}
