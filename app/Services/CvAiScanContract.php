<?php

namespace App\Services;

class CvAiScanContract
{
    public function schema(): array
    {
        $full = app(CvAiAssessment::class)->schema();
        $fields = ['schema_version', 'job_information', 'requirements', 'experience', 'warnings', 'professional_alignment',
            'candidate_background', 'false_positive_risk', 'suggestions', 'document_quality'];
        $properties = array_intersect_key($full['properties'], array_flip($fields));
        $requirement = &$properties['requirements']['items'];
        $requirement['properties'] = array_intersect_key($requirement['properties'], array_flip([
            'category', 'requirement', 'importance', 'evidence', 'match_classification', 'relevance', 'explanation',
        ]));
        $requirement['required'] = array_keys($requirement['properties']);
        return ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];
    }

    public function expand(array $data, string $source): array
    {
        // Only compact Version 2 responses use this adapter. Legacy fixtures and
        // saved reports keep their original contract and scoring behaviour.
        if (($data['schema_version'] ?? '') !== '2.0') return $data;
        foreach ($data['requirements'] as &$item) {
            $status = match ($item['match_classification'] ?? 'NOT_EVIDENCED') {
                'DIRECT_MATCH', 'EQUIVALENT_MATCH' => match ($item['category']) {
                    'competencies' => 'demonstrated', 'responsibilities' => 'directly_evidenced', default => 'matched',
                },
                'PARTIAL_MATCH' => in_array($item['category'], ['competencies', 'responsibilities'], true) ? 'partially_evidenced' : 'partially_matched',
                'TRANSFERABLE_MATCH' => $item['category'] === 'responsibilities' ? 'transferable_experience' : 'partially_matched',
                'STATED_ONLY' => $item['category'] === 'competencies' ? 'stated_only' : 'partially_matched',
                'UNCERTAIN' => 'uncertain', default => 'not_evidenced',
            };
            $item['original_status'] ??= $status;
            $item['revamped_status'] ??= $item['original_status'];
            $item['missing_information'] ??= '';
        }
        unset($item);
        foreach (CvAiAssessment::WEIGHTS as $category => $weight) {
            $data['categories'][$category] ??= ['applicability' => 'applicable', 'original_points' => 0, 'revamped_points' => 0,
                'explanation' => 'Calculated by ATSDetector from requirement evidence and relevance.'];
        }
        $parsed = app(CvTextParser::class)->parse($source);
        $data['candidate_profile'] ??= [
            'qualifications' => array_column($parsed['education'] ?? [], 'degree'),
            'certifications' => array_column($parsed['courses'] ?? [], 'title'),
            'skills' => array_values(array_filter(array_map('trim', preg_split('/[,\n]/u', $parsed['skills'] ?? '')))),
            'competencies' => [], 'experience' => array_column($parsed['experience'] ?? [], 'title'), 'projects' => [],
        ];
        foreach (['verified_information_to_highlight', 'information_requiring_confirmation', 'unsupported_job_requirements', 'questions_for_candidate', 'recommendations'] as $field) $data[$field] ??= [];
        $data['target_profession'] ??= $data['job_information']['title'] ?? '';
        $data['warnings'] ??= [];
        return $data;
    }
}
