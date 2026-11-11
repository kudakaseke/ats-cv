<?php

namespace Tests\Unit;

use App\Services\CvKeywordMatcher;
use App\Services\CvTextParser;
use App\Services\CvResumeFormat;
use PHPUnit\Framework\TestCase;

class CvCompetencyVocabularyTest extends TestCase
{
    public function test_competency_variants_match_once_and_keep_the_employer_label(): void
    {
        $matcher = new CvKeywordMatcher;
        $pairs = [
            'Problem-Solving' => 'finding practical solutions', 'Analytical Skills' => 'analytical mindset',
            'Communication' => 'excellent communicator', 'Attention to Detail' => 'meticulous',
            'Self-Motivation' => 'self-driven', 'Teamwork' => 'team player',
            'Adaptability' => 'adaptable to change', 'Integrity' => 'professional ethics',
            'Innovation' => 'creative thinking', 'Troubleshooting' => 'fault diagnosis',
            'Time Management' => 'manages competing priorities', 'Independence' => 'works autonomously',
            'Initiative' => 'takes initiative', 'Resilience' => 'stress tolerance',
            'Leadership' => 'people leadership', 'Customer Service' => 'customer care',
            'Organisational Skills' => 'effective coordination', 'Decision-Making' => 'sound judgement',
            'Learning Ability' => 'quick learner', 'Accountability' => 'takes ownership',
            'Relationship Building' => 'rapport building', 'Results Orientation' => 'goal-driven',
            'Critical Thinking' => 'logical reasoning', 'Professionalism' => 'work ethic',
            'Conflict Resolution' => 'mediation',
        ];
        foreach ($pairs as $requirement => $evidence) {
            $result = $matcher->compare("Technical skills\n$requirement", "Core Strengths\n$evidence");
            $this->assertSame([$requirement], $result['matched'], $requirement);
            $this->assertCount(1, $result['requirement_groups']['competencies'], $requirement);
        }
        $result = $matcher->compare("Competencies\nSelf-motivation\nSelf-starter\nSelf-driven", 'Self-motivated');
        $this->assertSame(1, $result['total']);
        $this->assertSame(100, $result['keyword_score']);
    }

    public function test_related_words_do_not_satisfy_more_specific_or_different_requirements(): void
    {
        $matcher = new CvKeywordMatcher;
        foreach ([['Written communication', 'Public speaking'], ['Written communication', 'Effective communication'], ['Software troubleshooting', 'Problem solving'], ['Initiative', 'Self-driven'], ['Accountability', 'Self-starter'], ['SQL', 'Analytical thinking'], ['Financial analysis', 'Analytical skills']] as [$requirement, $evidence]) {
            $result = $matcher->compare("Required skills\n$requirement", $evidence);
            $this->assertSame([], $result['matched'], "$requirement / $evidence");
        }
        $this->assertSame(0, $matcher->compare("Competencies\nSelf-motivation", 'Not a self-starter')['keyword_score']);
        $result = $matcher->compare("Competencies\nWritten communication\nOral communication", 'Strong verbal and written skills');
        $this->assertSame(100, $result['keyword_score']);
    }

    public function test_cv_heading_variants_are_separate_and_synonyms_are_deduplicated(): void
    {
        $parsed = (new CvTextParser)->parse("Alex Example\n1. Technical skills\nSQL, Python\n2. Core Strengths / Key Competences\nSelf-motivation, Self-starter, Analytical thinking");
        $this->assertSame('SQL, Python', $parsed['skills']);
        $this->assertStringContainsString('Self-starter', $parsed['core_strengths']);
        $data = CvResumeFormat::builder([...$parsed, 'skills' => 'SQL, Python, Team player, Self-driven, Software troubleshooting']);
        $this->assertSame('SQL, Python, Software troubleshooting', $data['skills']);
        $this->assertSame('Self-motivation, Analytical thinking, Team player', $data['core_strengths']);
    }
}
