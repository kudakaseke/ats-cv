<?php

namespace App\Services;

class CvRevampReviewRepair
{
    public function repair(array $draft, ?array $generated): array
    {
        $comparison = $draft['checker_result'] ?? [];
        if (! isset($generated['assessment'], $generated['structured_resume'], $generated['original_text'], $generated['job_description'], $generated['text'],
            $comparison['original_text'], $comparison['revised_text'], $comparison['job_description'], $draft['data'])
            || ! CvDocumentIdentity::same($generated['original_text'], $comparison['original_text'])
            || ! CvDocumentIdentity::same($generated['job_description'], $comparison['job_description'])
            || ! CvDocumentIdentity::same($generated['text'], $comparison['revised_text'])) {
            return [$draft, $generated];
        }
        $mapper = app(CvStructuredResume::class);
        // Never overwrite a CV the user has edited after generation.
        try {
            $currentText = $mapper->text($draft['data']);
        } catch (\Illuminate\Validation\ValidationException) {
            return [$draft, $generated];
        }
        if (! CvDocumentIdentity::same($currentText, $generated['text'])) {
            return [$draft, $generated];
        }
        $draft['data']['section_titles']['skills'] ??= 'Skills';
        $draft['data']['section_titles']['core_strengths'] ??= 'Competencies';
        $structured = app(CvSupportedTerms::class)->apply($generated['structured_resume'], $generated['assessment'], $generated['original_text']);
        if ($structured === $generated['structured_resume']) {
            return [$draft, $generated];
        }
        try {
            $mapped = $mapper->data($structured);
            $text = $mapper->text($mapped);
        } catch (\Illuminate\Validation\ValidationException) {
            return [$draft, $generated];
        }
        $draft['data']['skills'] = $mapped['skills'];
        $draft['data']['core_strengths'] = $mapped['core_strengths'];
        $draft['data']['imported_text'] = $text;
        $changes = array_slice($comparison['changes'] ?? [], 0, 29);
        $changes[] = 'Made source-evidenced job skills and competencies explicit in the CV sections.';
        $draft['checker_result'] = app(CvReviewComparison::class)->make($comparison['job_description'], $comparison['original_text'], $text, $changes);
        $draft['checker_result']['assessment'] = $generated['assessment'];
        $draft['checker_result'] = app(CvReviewComparison::class)->refreshForUser($draft['checker_result'], null);
        $generated['structured_resume'] = $structured;
        $generated['text'] = $text;

        return [$draft, $generated];
    }
}
