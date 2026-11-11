<?php

namespace App\Services;

class CvAiPrompt
{
    public function instructions(bool $rewrite): string
    {
        $prompt = file_get_contents(resource_path('prompts/atsdetector-production.txt'));
        if ($prompt === false || trim($prompt) === '') {
            throw new \RuntimeException('ATSDetector production prompt is unavailable.');
        }

        $common = "Do not invent experience, qualifications, certificates, employers, dates, numbers, software proficiency, achievements or results.\n";
        $mode = $rewrite
            ? "ACTIVE CALL MODE: FULL CV REWRITE. Follow the production prompt and return every required top-level key in the supplied response schema, including structured_resume, changes, unsupported_keywords and assessment. The supplied schema is authoritative. Keep the single-column professional CV structure; where populated, preserve the order Additional Experience, Technical Skills, Education, Certifications and Skills. Keep References last.\n"
            : "ACTIVE CALL MODE: ANALYSIS ONLY. Assess the original CV and job description, but do not rewrite, generate or return any CV content. Return only the top-level keys and fields required by the supplied analysis response schema. Set each requirement's revamped_status equal to original_status and each category's revamped_points equal to original_points. Keep recommendations to at most 12.\n";

        return rtrim($prompt)."\n\n".$common.$mode;
    }
}