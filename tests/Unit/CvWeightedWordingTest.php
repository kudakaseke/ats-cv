<?php

namespace Tests\Unit;

use App\Services\CvKeywordMatcher;
use PHPUnit\Framework\TestCase;

class CvWeightedWordingTest extends TestCase
{
    public function test_software_skills_under_qualifications_are_checked_in_skill_sections(): void
    {
        $job = "Qualifications:\nBachelor of Accounting\nProficiency in accounting software and Microsoft Excel.";
        $matcher = new CvKeywordMatcher;
        $before = $matcher->compare($job, "Education\nBachelor of Accounting\nSkills\nExcel");
        $after = $matcher->compare($job, "Education\nBachelor of Accounting\nSkills\nAccounting software\nMicrosoft Excel");
        $this->assertCount(1, $after['requirement_groups']['qualifications']);
        $this->assertCount(2, $after['requirement_groups']['skills']);
        $this->assertSame(67, $before['keyword_score']);
        $this->assertSame(100, $after['keyword_score']);
    }

    public function test_asset_maintenance_is_not_a_preference_indicator(): void
    {
        $result = (new CvKeywordMatcher)->compare("Skills\nAsset register maintenance\nQualifications\nProfessional accounting qualification will be an added advantage.", 'CV');
        $this->assertSame('required', $result['requirement_groups']['skills'][0]['importance']);
        $this->assertSame('preferred', $result['requirement_groups']['qualifications'][0]['importance']);
    }

    public function test_parenthetical_skill_examples_are_not_broken_into_fragments(): void
    {
        $result = (new CvKeywordMatcher)->compare("Skills\nTax reconciliation and compliance (VAT, PAYE, Income Tax, QPD).", 'CV');
        $this->assertNotContains('Tax reconciliation and compliance (VAT', $result['missing']);
        $this->assertNotContains('QPD)', $result['missing']);
        $this->assertContains('compliance (VAT, PAYE, Income Tax, QPD)', $result['missing']);
    }

    public function test_distinguishing_requirement_words_are_not_removed_as_filler(): void
    {
        $matcher = new CvKeywordMatcher;
        $this->assertSame(0, $matcher->compare('Skills: Team management', 'Management')['keyword_score']);
        $this->assertSame(0, $matcher->compare('Skills: Software development', 'Software')['keyword_score']);
        $this->assertSame(0, $matcher->compare('Skills: Technical support', 'Technical')['keyword_score']);
    }

    public function test_mandatory_standard_and_preferred_requirements_have_proportional_influence(): void
    {
        $job = "Mandatory skills\nVendor management\nSkills\nInventory control\nPreferred skills\nProcurement software";
        $matcher = new CvKeywordMatcher;
        $mandatory = $matcher->compare($job, 'Skills: Vendor management');
        $preferred = $matcher->compare($job, 'Skills: Procurement software');
        $this->assertSame(3, $mandatory['total']);
        $this->assertSame(6, $mandatory['total_weight']);
        $this->assertSame(3, $mandatory['matched_weight']);
        $this->assertSame(50, $mandatory['keyword_score']);
        $this->assertSame(17, $preferred['keyword_score']);
    }

    public function test_duplicate_requirements_keep_the_highest_priority_without_keyword_stuffing(): void
    {
        $matcher = new CvKeywordMatcher;
        $job = "Required skills\nSQL\nPreferred skills\nSQL, Python";
        $result = $matcher->compare($job, 'SQL SQL SQL');
        $this->assertSame(2, $result['total']);
        $this->assertSame(4, $result['total_weight']);
        $this->assertSame(75, $result['keyword_score']);
        $this->assertSame(25, $matcher->compare($job, 'Python')['keyword_score']);
    }

    public function test_company_benefits_and_application_details_do_not_change_coverage(): void
    {
        $matcher = new CvKeywordMatcher;
        $plain = "Required skills\nSQL\nPreferred skills\nPython";
        $advert = "About us\nKnowledge of our global retail brand is required.\n{$plain}\nBenefits\nMust enjoy free lunches.\nHow to apply\nSubmit a cover letter.\nClosing Date\n29 October 2026";
        $result = $matcher->compare($advert, 'SQL');
        $this->assertSame($matcher->compare($plain, 'SQL')['keyword_score'], $result['keyword_score']);
        $this->assertSame(['Python'], $result['missing']);
        $this->assertSame(0, $matcher->compare('Our company supplies global retail services and offers a great salary.', 'global retail salary')['total']);
    }

    public function test_unheaded_explicit_requirements_are_extracted_without_scoring_all_advert_words(): void
    {
        $result = (new CvKeywordMatcher)->compare("We are a growing international business.\nWe require SQL.\nApplicants must have Python.\nApplications close next week.", 'SQL');
        $this->assertSame(2, $result['total']);
        $this->assertSame(50, $result['keyword_score']);
        $this->assertSame(['Python'], $result['missing']);
    }

    public function test_precise_equivalents_match_but_related_technologies_do_not(): void
    {
        $matcher = new CvKeywordMatcher;
        $result = $matcher->compare("Skills\nPython (Programming Language), SQL\nCompetencies\nTeam player", "Skills\nPython, Structured Query Language\nStrengths\nTeamwork");
        $this->assertSame(100, $result['keyword_score']);
        $this->assertSame(0, $matcher->compare("Skills\nJava", 'JavaScript')['keyword_score']);
    }

    public function test_inline_item_priority_does_not_downgrade_the_other_skills(): void
    {
        $matcher = new CvKeywordMatcher;
        $result = $matcher->compare('Skills: SQL required, Python preferred', 'SQL');
        $this->assertSame(75, $result['keyword_score']);
        $this->assertSame(4, $result['total_weight']);
        $this->assertSame(1, $result['requirement_groups']['skills'][1]['weight']);
        $this->assertSame(100, $matcher->compare('Experience in vendor management is required.', 'Vendor management')['keyword_score']);
        $this->assertSame(75, $matcher->compare("Minimum requirements: SQL\nPreferred skills: Python", 'SQL')['keyword_score']);
    }
}
