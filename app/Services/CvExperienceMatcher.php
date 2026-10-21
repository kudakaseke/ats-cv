<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class CvExperienceMatcher
{
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
            if (! preg_match('/^(.+?)\s*(?:[-–—]|\bto\b)\s*(.+)$/iu', $period, $parts)) {
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
        if (preg_match('/^(present|current|now|ongoing)$/i', $value)) {
            return CarbonImmutable::now()->startOfMonth()->addMonth();
        }
        if (preg_match('/^\d{4}$/', $value)) {
            return CarbonImmutable::create((int) $value, $end ? 12 : 1, 1)->addMonths($end ? 1 : 0);
        }
        if (! preg_match('/^(?:[a-z]+\.?\s+\d{4}|\d{4}-(?:0[1-9]|1[0-2]))$/i', $value)) {
            throw new \InvalidArgumentException('Unknown date');
        }
        $date = preg_match('/^\d{4}-/', $value) ? CarbonImmutable::parse($value.'-01') : CarbonImmutable::parse('1 '.$value);

        return $date->startOfMonth()->addMonths($end ? 1 : 0);
    }
}
