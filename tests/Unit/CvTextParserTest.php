<?php

namespace Tests\Unit;

use App\Services\CvTextParser;
use PHPUnit\Framework\TestCase;

class CvTextParserTest extends TestCase
{
    public function test_detects_contact_and_requested_sections(): void
    {
        $text = <<<'TEXT'
Alex Morgan
alex@example.com | +27 71 234 5678
Professional Summary
Experienced engineer.
Work Experience
Position: Engineer
Place: Example Ltd
Location: Johannesburg
2022 - Present
Responsibilities: Build software
- Support customers
Position: Intern
Company: Another Ltd
Duties: Test software
Education
Qualification: BSc Computer Science
Institution: Example University
2018 - 2021
Skills: PHP, SQL
Key Competencies
Teamwork
References
Sam Smith, Manager, sam@example.com
Projects
Community website
TEXT;
        $data = (new CvTextParser)->parse($text);
        $this->assertSame('Alex Morgan', $data['name']);
        $this->assertSame('alex@example.com', $data['email']);
        $this->assertSame('+27 71 234 5678', $data['phone']);
        $this->assertSame('Experienced engineer.', $data['summary']);
        $this->assertCount(2, $data['experience']);
        $this->assertSame('Engineer', $data['experience'][0]['title']);
        $this->assertSame('Example Ltd', $data['experience'][0]['company']);
        $this->assertSame('Johannesburg', $data['experience'][0]['location']);
        $this->assertSame('2022 - Present', $data['experience'][0]['period']);
        $this->assertSame("Build software\n- Support customers", $data['experience'][0]['description']);
        $this->assertSame('Intern', $data['experience'][1]['title']);
        $this->assertSame('BSc Computer Science', $data['education'][0]['degree']);
        $this->assertSame('Example University', $data['education'][0]['school']);
        $this->assertSame('PHP, SQL', $data['skills']);
        $this->assertSame('Teamwork', $data['competencies']);
        $this->assertStringContainsString('Sam Smith', $data['references']);
        $this->assertSame([['title' => 'Projects', 'content' => 'Community website']], $data['custom_sections']);
    }

    public function test_unlabelled_entries_and_missing_fields_are_supported(): void
    {
        $data = (new CvTextParser)->parse("Jane Smith\nExperience\nDeveloper at Acme\n2020 - Present\n- Build apps\n\nAssistant\nOther Company\n- Support staff\nEducation\nDiploma\nLocal College");
        $this->assertSame('', $data['email']);
        $this->assertSame('', $data['phone']);
        $this->assertCount(2, $data['experience']);
        $this->assertSame('Acme', $data['experience'][0]['company']);
        $this->assertSame('- Build apps', $data['experience'][0]['description']);
        $this->assertSame('Local College', $data['education'][0]['school']);
    }

    public function test_reference_contacts_are_not_used_as_the_applicants_contacts(): void
    {
        $data = (new CvTextParser)->parse("Alex Morgan\nSummary\nAn experienced engineer.\nReferences\nSam Smith | +27 71 234 5678 | sam@example.com");
        $this->assertSame('', $data['email']);
        $this->assertSame('', $data['phone']);
        $this->assertStringContainsString('sam@example.com', $data['references']);
    }

    public function test_inline_dates_do_not_remove_a_position_or_qualification(): void
    {
        $data = (new CvTextParser)->parse("Alex Morgan\nExperience\nEngineer at Acme | Mar 2022 - Present\n- Build applications\nEducation\nBSc Computing | 2018 - 2021\nExample University\nCertification\nData Science Certificate\nCore Strengths\nCommunication and teamwork");
        $this->assertSame('Engineer', $data['experience'][0]['title']);
        $this->assertSame('Acme', $data['experience'][0]['company']);
        $this->assertSame('Mar 2022 - Present', $data['experience'][0]['period']);
        $this->assertCount(1, $data['education']);
        $this->assertSame('BSc Computing', $data['education'][0]['degree']);
        $this->assertSame('2018 - 2021', $data['education'][0]['period']);
        $this->assertSame('Communication and teamwork', $data['core_strengths']);
        $this->assertSame('Courses', $data['custom_sections'][0]['title']);
    }

    public function test_dates_separated_by_a_blank_line_stay_with_the_qualification(): void
    {
        $data = (new CvTextParser)->parse("Alex Morgan\nEducation\nBSc Computing\nExample University\n\n2021 - 2025\nCertification\nData Science Certificate");
        $this->assertCount(1, $data['education']);
        $this->assertSame('BSc Computing', $data['education'][0]['degree']);
        $this->assertSame('Example University', $data['education'][0]['school']);
        $this->assertSame('2021 - 2025', $data['education'][0]['period']);
    }

    public function test_core_strengths_and_competencies_are_separate_sections(): void
    {
        $data = (new CvTextParser)->parse("Alex Morgan\nCore Strength\nAdaptability\nKey Competencies\nProject planning\nReferences\nAvailable on request");
        $this->assertSame('Adaptability', $data['core_strengths']);
        $this->assertSame('Project planning', $data['competencies']);
    }
}
