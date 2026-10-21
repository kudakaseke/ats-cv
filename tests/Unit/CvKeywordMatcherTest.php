<?php

namespace Tests\Unit;

use App\Services\CvKeywordMatcher;
use PHPUnit\Framework\TestCase;

class CvKeywordMatcherTest extends TestCase
{
    public function test_equivalent_terms_and_common_word_forms_count_as_matches(): void
    {
        $result = (new CvKeywordMatcher)->compare('CSS SQL Microsoft Excel reporting budgeting', 'Cascading Style Sheets, Structured Query Language, MS Excel, reports, budgets');
        $this->assertSame(100, $result['keyword_score']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(0, (new CvKeywordMatcher)->compare('Python', 'JavaScript')['keyword_score']);
    }

    public function test_more_requirement_headings_exclude_company_and_benefit_words(): void
    {
        $result = (new CvKeywordMatcher)->compare("Company overview\nGlobal insurance business\n2. Qualifications and experience:\nSQL and Excel\nBenefits\nTravel allowance", 'SQL and Microsoft Excel');
        $this->assertSame('requirements', $result['keyword_source']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(100, $result['keyword_score']);
    }

    public function test_score_changes_only_when_resume_keywords_change(): void
    {
        $matcher = new CvKeywordMatcher;
        $this->assertSame(0, $matcher->compare('PHP, HTML and CSS', 'C# and Python')['score']);
        $result = $matcher->compare('PHP, HTML and CSS', 'C#, Python, PHP, HTML');
        $this->assertSame(41, $result['score']);
        $this->assertSame(67, $result['keyword_score']);
        $this->assertEqualsWithDelta(41.1207, $result['similarity_percent'], 0.01);
        $this->assertSame(['CSS'], $result['missing']);
        $this->assertSame(['PHP', 'HTML'], $result['matched']);
    }

    public function test_aliases_case_punctuation_and_repetition_do_not_distort_scores(): void
    {
        $matcher = new CvKeywordMatcher;
        $result = $matcher->compare('Required skills: PHP PHP, HTML5, CSS3 and JS.', 'php html css JavaScript');
        $this->assertSame(100, $result['keyword_score']);
        $this->assertLessThan(100, $result['score']);
        $this->assertSame(4, $result['total']);
        $this->assertSame(0, $matcher->compare('Java C# C++ SQL', 'JavaScript C MySQL')['score']);
        $this->assertSame(100, $matcher->compare('.NET, C# and C++', 'dotnet csharp cplusplus')['score']);
        $this->assertSame(100, $matcher->compare('REST APIs Node.js', 'rest API nodejs')['score']);
    }

    public function test_empty_or_filler_only_description_has_no_keywords(): void
    {
        $this->assertSame(0, (new CvKeywordMatcher)->compare('We are looking for a candidate', 'PHP')['total']);
    }

    public function test_cosine_similarity_and_coverage_are_distinct_and_have_sensible_bounds(): void
    {
        $matcher = new CvKeywordMatcher;
        $this->assertSame(100, $matcher->compare('PHP HTML CSS', 'PHP HTML CSS')['score']);
        $this->assertSame(0, $matcher->compare('PHP HTML CSS', 'Python')['score']);
        $short = $matcher->compare('PHP HTML CSS', 'PHP HTML CSS Python');
        $long = $matcher->compare('PHP HTML CSS', 'PHP HTML CSS Python Python Python Python');
        $this->assertSame(100, $short['keyword_score']);
        $this->assertSame(100, $long['keyword_score']);
        $this->assertGreaterThan($long['score'], $short['score']);
        $this->assertSame(0, $matcher->compare('', '')['score']);
    }

    public function test_requirements_drive_keyword_suggestions_without_scoring_company_boilerplate_as_a_requirement(): void
    {
        $result = (new CvKeywordMatcher)->compare("About us\nAcme retail\n**Requirements:**\nPHP PHP HTML CSS\nBenefits: Free lunches", 'PHP Python');
        $this->assertSame('requirements', $result['keyword_source']);
        $this->assertSame(['HTML', 'CSS'], $result['missing']);
        $this->assertSame(3, $result['total']);
        $this->assertSame('HTML', $result['suggestions'][0]['keyword']);
    }
}
