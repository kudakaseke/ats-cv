<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class CvExperienceMatcher
{
    public static function periodPattern(): string
    {
        $date = '(?:(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(?:19|20)\d{2}|(?:0?[1-9]|1[0-2])\/(?:19|20)\d{2}|(?:19|20)\d{2}(?:-(?:0[1-9]|1[0-2]))?)';
        return '/\b('.$date.')\s*(?:[-\x{2013}\x{2014}]|\bto\b)\s*('.$date.'|present|current|now|ongoing|to date)\b/iu';
    }

    public function reconcile(array $check, ?array $assessment): array
    {
        $experience = $assessment['experience'] ?? null;
        if (! is_array($experience)) {
            return $check;
        }
        $relevant = $experience['documented_relevant_years'] ?? null;
        $total = $experience['total_professional_years'] ?? null;
        $valid = fn ($value) => is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 100;
        $relevant = $valid($relevant) ? (float) $relevant : null;
        $total = $valid($total) ? (float) $total : null;
        if ($relevant !== null && $total !== null && $relevant > $total) {
            return $check; // Contradictory estimates must not replace the date check.
        }
        if ($relevant === null && $total === null) {
            return $check;
        }
        $required = $check['required_years'] ?? null;
        if (array_key_exists('required_years', $experience) && ($experience['required_years'] === null || $valid($experience['required_years']))) {
            $required = $experience['required_years'] === null ? null : (float) $experience['required_years'];
            $check['required_years'] = $required;
        }
        $check['cv_years'] = $total ?? $check['cv_years'] ?? null;
        $check['relevant_years'] = $relevant;
        $check['source'] = 'ai_evidence_assessment';
        $check['shortfall'] = $required !== null && $relevant !== null && $relevant < $required;
        $parts = [];
        $parts[] = $required === null ? 'No explicit minimum number of years was detected in the job description.' : "The job mentions {$required} years of experience.";
        if ($total !== null) $parts[] = "The AI evidence assessment estimates approximately {$total} years of total professional experience.";
        if ($relevant !== null) $parts[] = "Approximately {$relevant} years were assessed as relevant to this role.";
        if ($check['shortfall']) $parts[] = 'The assessed relevant experience is below the stated minimum.';
        elseif ($required !== null && $relevant !== null) $parts[] = 'The assessed relevant experience meets the stated minimum duration.';
        elseif ($required !== null) $parts[] = 'Relevant experience could not be confirmed; total experience alone does not establish that the requirement is met.';
        $check['message'] = implode(' ', $parts);
        $check['note'] = 'These are AI estimates based on CV evidence, not independent verification. Review the employment dates and relevance of each role.';
        $check['assessment_date_warnings'] = $experience['date_warnings'] ?? [];
        if ($check['assessment_date_warnings']) $check['note'] .= ' Date details need review: '.implode('; ', $check['assessment_date_warnings']);
        return $check;
    }

    public function compare(string $job, string $resume): array
    {
        $numbers = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'eleven' => '11', 'twelve' => '12'];
        $normalise = fn ($text) => preg_replace_callback('/\b('.implode('|', array_keys($numbers)).')\b/i', fn ($match) => $numbers[strtolower($match[1])], $text);
        $job = $normalise($job);
        $pattern = '/\b(\d+(?:\.\d+)?)\s*(?:[-–]\s*\d+)?\s*\+?\s*years?[\s’\x{0027}]*(?:of\s+)?(?:[a-z-]+\s+){0,5}experience\b/iu';
        preg_match_all($pattern, $job, $requirements, PREG_SET_ORDER);
        $required = null;
        $evidence = null;
        foreach ($requirements as $match) {
            if ($required === null || (float) $match[1] > $required) {
                $required = (float) $match[1];
                $evidence = $match[0];
            }
        }
        preg_match_all('/(?:required|relevant|minimum)\s+experience\s*:\s*(\d+(?:\.\d+)?)\s*(years?|months?)\b/iu', $job, $labelled, PREG_SET_ORDER);
        foreach ($labelled as $match) {
            $value = (float) $match[1] / (str_starts_with(strtolower($match[2]), 'month') ? 12 : 1);
            if ($required === null || $value > $required) {
                $required = round($value, 2);
                $evidence = $match[0];
            }
        }
        $months = [];
        $unreadable = [];
        $data = (new CvTextParser)->parse($resume);
        foreach ($data['experience'] ?? [] as $row) {
            $period = $row['period'] ?? '';
            if (! preg_match(self::periodPattern(), $period, $parts) || trim($parts[0]) !== trim($period)) {
                if (trim($period) !== '') {
                    $unreadable[] = $period;
                }

                continue;
            }
            try {
                $start = $this->date($parts[1], false);
                $end = $this->date($parts[2], true)->min(CarbonImmutable::now()->startOfMonth()->addMonth());
                if ($start >= $end) {
                    continue;
                }
                for ($date = $start; $date < $end; $date = $date->addMonth()) {
                    $months[$date->format('Y-m')] = true;
                }
            } catch (\Throwable) {
                $unreadable[] = $period;
                // Unrecognised dates are unknown, never zero years of experience.
            }
        }
        $years = $months ? round(count($months) / 12, 1) : null;
        $source = $months ? 'employment dates' : null;
        if ($years === null && preg_match($pattern, $normalise($resume), $claim)) {
            $years = (float) $claim[1];
            $source = 'a statement in your CV';
        }
        $shortfall = $required !== null && $years !== null && $years < $required;
        $requiredDisplay = $required.' '.($required == 1 ? 'year' : 'years');
        $message = $required === null ? 'No explicit minimum number of years was detected in the job description.'
            : ($years === null ? "The job mentions {$requiredDisplay} of experience. We could not estimate your experience from the CV; check your employment dates."
                : "The job mentions {$requiredDisplay} of experience; your CV shows approximately {$years} years in total, based on {$source}.".($shortfall ? ' Your CV shows fewer years than requested.' : ' Check that these years are relevant to the required role.'));

        return ['required_years' => $required, 'cv_years' => $years, 'shortfall' => $shortfall, 'requirement' => $evidence, 'message' => $message, 'unreadable_dates' => array_values(array_unique($unreadable)),
            'note' => 'This is an estimate of total experience, not confirmed experience in the required field. Overlapping jobs count once. Year-only dates are approximate.'];
    }

    private function date(string $value, bool $end): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^(present|current|now|ongoing|to date)$/i', $value)) {
            return CarbonImmutable::now()->startOfMonth()->addMonth();
        }
        if (preg_match('/^\d{4}$/', $value)) {
            return CarbonImmutable::create((int) $value, $end ? 12 : 1, 1)->addMonths($end ? 1 : 0);
        }
        if (preg_match('/^(0?[1-9]|1[0-2])\/(\d{4})$/', $value, $numeric)) {
            return CarbonImmutable::create((int) $numeric[2], (int) $numeric[1], 1)->addMonths($end ? 1 : 0);
        }
        if (! preg_match('/^(?:[a-z]+\.?\s+\d{4}|\d{4}-(?:0[1-9]|1[0-2]))$/i', $value)) {
            throw new \InvalidArgumentException('Unknown date');
        }
        $date = preg_match('/^\d{4}-/', $value) ? CarbonImmutable::parse($value.'-01') : CarbonImmutable::parse('1 '.$value);

        return $date->startOfMonth()->addMonths($end ? 1 : 0);
    }
}
