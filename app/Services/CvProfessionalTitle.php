<?php

namespace App\Services;

class CvProfessionalTitle
{
    public function preserve(string $source, string $proposed): string
    {
        $lines = preg_split('/\R/u', $source);
        $header = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?:professional title|professional headline|role|job title|position)\s*:\s*(.{1,150})$/iu', trim($line), $match)) {
                return trim($match[1]);
            }
            if (preg_match('/^(?:professional summary|summary|personal profile|profile|career objective|objective|experience|work experience|professional experience|employment history|education|qualifications|skills|technical skills|references|referees)\s*:?/iu', trim($line))) {
                break;
            }
            $header[] = $line;
        }
        // A headline is optional. Never manufacture a profession from the advert.
        // Requiring a whole source line also excludes incidental mentions in duties.
        $normalise = fn (string $value) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
        $candidate = $normalise($proposed);
        if ($candidate !== '') {
            foreach ($header as $line) {
                if ($normalise($line) === $candidate) {
                    return trim($line);
                }
            }
        }

        foreach (array_slice($header, 1, 5) as $line) {
            $line = trim($line);
            if (mb_strlen($line) <= 150 && preg_match('/\b(?:accountant|auditor|engineer|developer|officer|manager|analyst|administrator|specialist|consultant|nurse|technician|clerk)\b/iu', $line)
                && ! preg_match('/[@:;.!?]|https?:/iu', $line)) {
                return $line;
            }
        }

        return '';
    }
}
