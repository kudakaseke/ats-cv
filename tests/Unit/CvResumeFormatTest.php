<?php

namespace Tests\Unit;

use App\Services\CvResumeFormat;
use App\Services\CvTextParser;
use Tests\TestCase;

class CvResumeFormatTest extends TestCase
{
    public function test_education_class_is_parsed_separately_from_the_degree(): void
    {
        $data = app(CvTextParser::class)->parse("Alex Applicant\nEducation\nDegree: Bachelor of Commerce\nSchool: Example College\nDegree class/grade: Upper Second Class (2:1)\nPeriod: 2020 - 2024");
        $this->assertSame('Bachelor of Commerce', $data['education'][0]['degree']);
        $this->assertSame('Upper Second Class (2:1)', $data['education'][0]['grade']);
    }

    public function test_legacy_strengths_and_competencies_merge_without_duplicates_or_losing_items(): void
    {
        $data = CvResumeFormat::builder(['core_strengths' => "Communication | Teamwork\nAttention to detail", 'competencies' => 'teamwork, Problem solving']);
        $this->assertSame('Communication, Teamwork, Attention to detail, Problem solving', $data['core_strengths']);
        $this->assertSame('', $data['competencies']);
        $this->assertSame('', $data['references']);
    }

    public function test_references_keep_their_own_lines_and_contacts_without_inventing_missing_details(): void
    {
        $data = CvResumeFormat::builder(['references' => "  Jane Example| Manager |  0123456789 |jane@example.invalid\n\nSam Example | Lecturer | sam@example.invalid"]);
        $this->assertSame("Jane Example | Manager | 0123456789 | jane@example.invalid\nSam Example | Lecturer | sam@example.invalid", $data['references']);
    }

    public function test_referee_name_labels_cannot_overwrite_the_applicant_name_or_contacts(): void
    {
        $data = app(CvTextParser::class)->parse("Alex Applicant\napplicant@example.invalid\nStrengths and Competencies\nTeamwork\nReferences\nName: Jane Referee\njane@example.invalid\n0123456789");
        $this->assertSame('Alex Applicant', $data['name']);
        $this->assertSame('applicant@example.invalid', $data['email']);
        $this->assertSame('', $data['phone']);
        $this->assertStringContainsString('Name: Jane Referee', $data['references']);
        $this->assertSame('Teamwork', $data['core_strengths']);
    }
}
