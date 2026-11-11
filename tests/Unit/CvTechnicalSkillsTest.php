<?php

namespace Tests\Unit;

use App\Services\CvTechnicalSkills;
use PHPUnit\Framework\TestCase;

class CvTechnicalSkillsTest extends TestCase
{
    public function test_software_rows_follow_the_reference_order_without_adding_skills(): void
    {
        $skills = "Methods: Agile\nTools: Git, RESTful APIs, Microsoft 365, Microsoft Power BI\nDatabases: MySQL, MongoDB\nProgramming: Python, PHP, JavaScript, SQL, HTML5, CSS3\nFrameworks: Laravel, Machine Learning (Scikit-Learn)";
        $formatted = (new CvTechnicalSkills)->format($skills, 'Web Developer');
        $this->assertSame("Programming: Python, PHP, JavaScript, SQL, HTML5, CSS3\nFrameworks: Laravel, Machine Learning (Scikit-Learn)\nDatabases: MySQL, MongoDB\nTools: Git, RESTful APIs, Microsoft 365, Microsoft Power BI\nMethods: Agile", $formatted);
        $this->assertSame($formatted, (new CvTechnicalSkills)->format($formatted, 'Web Developer'));
    }

    public function test_accounting_uses_its_own_categories_and_preserves_proficiency(): void
    {
        $skills = "Leadership: Cross-functional Team Development, KPI-Driven Supervision, Strategic Planning\nCompliance: US GAAP, Federal Contract Reporting, Audit Management\nTechnical: Sage Intacct (Advanced), Oracle, Dynamics GP, Power BI, Tableau, SQL, Python";
        $formatted = (new CvTechnicalSkills)->format($skills, 'Senior Accountant');
        $this->assertStringStartsWith('Technical: Sage Intacct (Advanced)', $formatted);
        $this->assertSame(['Technical', 'Compliance', 'Leadership'], array_map(fn ($line) => explode(':', $line)[0], explode("\n", $formatted)));
        $this->assertStringNotContainsString('Programming:', $formatted);
    }

    public function test_flat_engineering_and_management_skills_use_domain_categories(): void
    {
        $formatter = new CvTechnicalSkills;
        $this->assertSame("Design: Structural design\nTools: AutoCAD\nStandards: ISO 9001\nSafety: HAZOP", $formatter->format('AutoCAD, HAZOP, Structural design, ISO 9001', 'Civil Engineer'));
        $this->assertSame("Strategy: Strategic planning\nOperations: Budgeting\nDelivery: Project delivery\nTools: Microsoft Excel\nGovernance: Risk management", $formatter->format('Budgeting, Microsoft Excel, Strategic planning, Risk management, Project delivery', 'Operations Manager'));
    }

    public function test_unknown_professions_keep_supplied_categories_and_omit_empty_groups(): void
    {
        $formatter = new CvTechnicalSkills;
        $this->assertSame("Clinical: Patient assessment\nSystems: Electronic health records", $formatter->format("Clinical: Patient assessment\nSystems: Electronic health records", 'Nurse'));
        $this->assertSame('Programming: Python', $formatter->format('Python', 'Software Developer'));
        $this->assertSame('SQL, Python', $formatter->format('SQL, Python'));
    }
    public function test_misplaced_databases_and_repeated_tool_labels_are_repaired(): void
    {
        $skills = "Tools: Git, MySQL, MongoDB\nMethods: Agile\nDatabase: MySQL\nFrameworks: Laravel\nTools and technologies: Microsoft 365, Git\nProgramming: Python, SQL";
        $formatted = (new CvTechnicalSkills)->format($skills);
        $this->assertSame("Programming: Python, SQL\nFrameworks: Laravel\nDatabases: MySQL, MongoDB\nTools: Git, Microsoft 365\nMethods: Agile", $formatted);
        $this->assertSame($formatted, (new CvTechnicalSkills)->format($formatted));
    }

    public function test_long_tools_group_has_one_label_without_losing_items(): void
    {
        $items = array_map(fn ($index) => 'Specialised software package '.$index, range(1, 12));
        $formatted = (new CvTechnicalSkills)->format('Tools: '.implode(', ', $items), 'Software Developer');
        $this->assertGreaterThan(200, strlen($formatted));
        $this->assertSame(1, substr_count($formatted, 'Tools:'));
        foreach ($items as $item) $this->assertStringContainsString($item, $formatted);
    }

    public function test_rewrite_does_not_infer_skill_durations_or_repeat_annotated_skills(): void
    {
        $formatter = new CvTechnicalSkills;
        $this->assertSame("Programming: PHP\nDatabases: MySQL\nTools: Git", $formatter->format(
            "Programming: PHP (2.9 years), PHP\nDatabases: MySQL (Advanced), MySQL\nTools: Git (Expert), Git",
            'Software Developer', 'Used PHP, MySQL and Git from January 2023 to December 2025.'
        ));
        $this->assertSame('Technical: Sage Intacct (Advanced)', $formatter->format('Technical: Sage Intacct (Advanced), Sage Intacct', 'Accountant', 'Skills: Sage Intacct (Advanced)'));
        $this->assertSame('Programming: PHP (3 years)', $formatter->format('Programming: PHP (3 years), PHP', 'Developer', 'Programming: PHP (3 years)'));
    }

}
