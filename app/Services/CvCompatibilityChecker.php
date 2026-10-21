<?php

namespace App\Services;

class CvCompatibilityChecker
{
    public function check(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $checks = [];
        $add = function (string $label, bool $passed, string $advice) use (&$checks) {
            $checks[] = compact('label', 'passed', 'advice');
        };
        $heading = fn (string $pattern): bool => (bool) preg_match('/^\h*(?:'.$pattern.')\h*:?\h*$/imu', $text);
        $add('Readable CV text', count($words) >= 100, 'Use selectable text and include enough detail about your background. Image-only CVs cannot be read by this check.');
        $add('Email address', (bool) preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text), 'Include your email address in your contact details.');
        $add('Phone number', (bool) preg_match('/(?<!\d)\+?\d[\d ().-]{5,}\d(?!\d)/', preg_replace('/\b\d{4}\h*[-–]\h*\d{4}\b/u', '', $text)), 'Include a phone number with its country or area code.');
        $add('Experience section', $heading('(?:(?:professional|work|employment|relevant)\h+)?(?:experience|history)|employment'), 'Use a clear heading such as Work Experience. Include internships or volunteering where relevant.');
        $add('Education section', $heading('education(?:\h*(?:and|&)\h*qualifications)?|qualifications|academic\h+background'), 'Use a clear Education or Qualifications heading for your studies.');
        $add('Skills section', $heading('(?:(?:technical|core|key|professional)\h+)?(?:skills|competencies)(?:\h*(?:and|&)\h*(?:skills|competencies))?'), 'Include a Skills or Technical Skills section with skills you can demonstrate.');
        $add('Recognisable dates', (bool) preg_match('/\b(?:19|20)\d{2}\b/', $text), 'Include dates for your employment or education, using a consistent month/year or year format.');
        $passed = count(array_filter($checks, fn ($check) => $check['passed']));

        return [
            'checks' => $checks,
            'passed' => $passed,
            'total' => count($checks),
            'summary' => $passed === count($checks) ? 'Your CV passes the basic text and structure checks.' : 'Some parts of your CV need a closer look.',
            'scope' => 'This checks the readable text and section headings. It does not assess the original page layout or predict acceptance by an employer’s ATS. Use ATS Scanner to compare your CV with a specific job.',
        ];
    }
}
