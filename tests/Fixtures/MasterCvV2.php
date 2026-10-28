<?php

namespace Tests\Fixtures;

use App\Services\CvAiAssessment;

class MasterCvV2
{
    public const SOURCE = "Alex Example\nPHP Developer\nSummary\nBuilds PHP applications.\nSkills\nPHP\nEducation\nDiploma in Computing";

    public static function assessment(): array
    {
        $data = ['schema_version' => '2.0',
            'job_information' => ['title' => 'PHP Developer', 'industry' => 'Software', 'seniority' => 'Junior', 'employment_type' => ''],
            'candidate_profile' => array_fill_keys(['qualifications', 'certifications', 'skills', 'competencies', 'experience', 'projects'], []),
            'requirements' => [
                ['category' => 'skills', 'requirement' => 'PHP', 'importance' => 'mandatory', 'evidence' => 'Builds PHP applications.', 'original_status' => 'matched', 'revamped_status' => 'matched', 'relevance' => 'core'],
                ['category' => 'certifications', 'requirement' => 'AWS certification', 'importance' => 'mandatory', 'evidence' => '', 'original_status' => 'not_evidenced', 'revamped_status' => 'not_evidenced', 'relevance' => 'core'],
            ],
            'experience' => ['required_years' => null, 'documented_relevant_years' => null, 'total_professional_years' => null, 'explanation' => 'No minimum stated.', 'date_warnings' => []],
            'warnings' => [], 'verified_information_to_highlight' => ['PHP'], 'information_requiring_confirmation' => [],
            'unsupported_job_requirements' => [], 'questions_for_candidate' => [], 'recommendations' => [],
            'document_quality' => ['extraction_limitations' => [], 'uncertainties' => []],
            'suggestions' => [['id' => 'suggestion-1', 'section' => 'Skills', 'problem' => 'Skill should remain visible.',
                'improvement' => 'Keep PHP prominent.', 'reason' => 'Required by the employer.', 'related_requirement' => 'PHP', 'can_apply_now' => true]],
        ];
        foreach (CvAiAssessment::WEIGHTS as $category => $maximum) {
            $data['categories'][$category] = ['applicability' => 'applicable', 'original_points' => 0, 'revamped_points' => 0, 'explanation' => 'Laravel calculates points.'];
        }
        return $data;
    }

    public static function draft(): array
    {
        return ['structured_resume' => ['name' => 'Alex Example', 'role' => 'PHP Developer', 'email' => '', 'phone' => '', 'location' => '', 'website' => '',
            'summary' => 'PHP developer building PHP applications.', 'skills' => ['PHP'], 'core_strengths' => [], 'competencies' => [], 'references' => '',
            'experience' => [], 'education' => [['degree' => 'Diploma in Computing', 'school' => '', 'period' => '', 'location' => '', 'grade' => '']],
            'courses' => [], 'achievements' => [], 'languages' => [], 'custom_sections' => []],
            'changes' => ['Made PHP visible.'], 'unsupported_keywords' => ['AWS certification'], 'assessment' => self::assessment()];
    }

    public static function validation(): array
    {
        return ['approved' => true, 'issues' => [], 'assessment' => self::assessment(),
            'keyword_coverage' => [
                ['category' => 'skills', 'requirement' => 'PHP', 'evidence' => 'Builds PHP applications.', 'supported' => true, 'represented' => true, 'explanation' => 'PHP is in Skills.'],
                ['category' => 'certifications', 'requirement' => 'AWS certification', 'evidence' => '', 'supported' => false, 'represented' => false, 'explanation' => 'Not evidenced; recommend confirmation or certification.'],
            ],
            'ai_changes' => [['id' => 'change-1', 'section' => 'Professional Summary', 'change_type' => 'summary_rewritten',
                'original_text' => 'Builds PHP applications.', 'revised_text' => 'PHP developer building PHP applications.', 'explanation' => 'Clarified the professional summary using the documented PHP work.',
                'related_requirement' => 'PHP', 'supporting_evidence' => 'Builds PHP applications.', 'improves_keyword_coverage' => false, 'affects_assessment' => false]],
        ];
    }

    public static function response(array $data, int $input = 100, int $output = 50): array
    {
        return ['status' => 'completed', 'model' => 'gpt-6.1-sol', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]],
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output]];
    }

}
