<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class CvExperienceMatcher
{
    public static function periodPattern(): string
    {
        $month = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?';
        $date = '(?:\d{1,2}\s+'.$month.'\s+(?:19|20)\d{2}|'.$month.'\s+(?:19|20)\d{2}|\d{1,2}/\d{1,2}/(?:19|20)\d{2}|(?:0?[1-9]|1[0-2])/(?:19|20)\d{2}|(?:19|20)\d{2}(?:-(?:0[1-9]|1[0-2])(?:-\d{2})?)?)';
        // PDF extraction can join a title and its year: Developer2025 - Present.
        return '~(?<!\d)('.$date.')\s*(?:[-\x{2013}\x{2014}]|\bto\b)\s*('.$date.'|present|current|now|ongoing|to date)\b~iu';
    }

    public function reconcile(array $check, ?array $assessment): array
    {
        $experience = $assessment['experience'] ?? null;
        if (! is_array($experience)) {
            return $check;
        }
        $relevant = $experience['documented_relevant_years'] ?? null;
        // The dates parsed from this exact CV are authoritative. An AI estimate
        // must never replace the independently calculated calendar duration.
        $total = $check['cv_years'] ?? null;
        $valid = fn ($value) => is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 100;
        if ($valid($relevant) && $valid($experience['total_professional_years'] ?? null) && $relevant > $experience['total_professional_years']) return $check;
        $relevant = $valid($relevant) ? (float) $relevant : null;
        $total = $valid($total) ? (float) $total : null;
        if ($total === null || ($check['date_precision'] ?? '') === 'year') $relevant = null;
        if ($relevant !== null && $total !== null && $relevant > $total) {
            return $check; // Contradictory estimates must not replace the date check.
        }
        if ($relevant === null && $total === null) {
            return $check;
        }
        $required = $check['required_years'] ?? null;
        if ($required === null && array_key_exists('required_years', $experience) && $valid($experience['required_years'])) {
            $required = $experience['required_years'] === null ? null : (float) $experience['required_years'];
            $check['required_years'] = $required;
        }
        $check['cv_years'] = $total ?? $check['cv_years'] ?? null;
        $check['relevant_years'] = $relevant;
        $check['source'] = 'cv_employment_dates';
        $check['shortfall'] = $required !== null && ($relevant !== null ? $relevant < $required : (($check['maximum_calendar_years'] ?? null) !== null && $check['maximum_calendar_years'] < $required));
        $parts = [];
        $parts[] = $required === null ? 'No explicit minimum number of years was detected in the job description.' : "The job mentions {$required} years of experience.";
        if ($total !== null) $parts[] = "The CV dates indicate approximately {$total} calendar years across the documented work periods, not confirmed full-time experience.";
        if (($check['date_precision'] ?? '') === 'year') {
            $parts[] = 'Only years are supplied: exact duration is unknown. The possible calendar range is '.$check['minimum_calendar_years'].'–'.$check['maximum_calendar_years'].' years; provide months to confirm it.';
        }
        if ($relevant !== null) $parts[] = "Approximately {$relevant} years were assessed as relevant to this role.";
        if ($check['shortfall']) $parts[] = 'The assessed relevant experience is below the stated minimum.';
        elseif ($required !== null && $relevant !== null) $parts[] = 'The assessed relevant experience meets the stated minimum duration.';
        elseif ($required !== null) $parts[] = 'Relevant experience could not be confirmed; total experience alone does not establish that the requirement is met.';
        $check['message'] = implode(' ', $parts);
        $check['note'] = 'Employment duration is calculated from CV dates with overlaps counted once. Freelance work and attachments are not automatically full-time or relevant to the advertised role.';
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
        $intervals = $minimumIntervals = $maximumIntervals = [];
        $periods = [];
        $yearOnly = false;
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
                $today = CarbonImmutable::now('Africa/Johannesburg')->startOfDay()->addDay();
                $end = $this->date($parts[2], true)->min($today);
                $uncertainStart = (bool) preg_match('/^\d{4}$/', $parts[1]);
                $uncertainEnd = (bool) preg_match('/^\d{4}$/', $parts[2]);
                $yearOnly = $yearOnly || $uncertainStart || $uncertainEnd;
                $earliestStart = $start;
                $latestStart = $uncertainStart ? $start->addYear() : $start;
                $earliestEnd = $end;
                $latestEnd = $uncertainEnd ? $this->date($parts[2], true)->addYear()->min($today) : $end;
                if ($earliestStart >= $latestEnd) {
                    $unreadable[] = $period;
                    continue;
                }
                if ($start < $end) $intervals[] = [$start, $end];
                if ($latestStart < $earliestEnd) $minimumIntervals[] = [$latestStart, $earliestEnd];
                $maximumIntervals[] = [$earliestStart, $latestEnd];
                $role = implode(' ', [$row['title'] ?? '', $row['company'] ?? '']);
                $type = preg_match('/\b(?:attachment|internship|intern|apprentice|trainee)\b/i', $role) ? 'attachment'
                    : (preg_match('/\b(?:freelance|self.employed)\b/i', $role) ? 'freelance' : 'employment');
                $periods[] = ['period' => $period, 'type' => $type, 'precision' => $uncertainStart || $uncertainEnd ? 'year' : 'month_or_day'];
            } catch (\Throwable) {
                $unreadable[] = $period;
                // Unrecognised dates are unknown, never zero years of experience.
            }
        }
        $years = $intervals ? $this->unionYears($intervals) : null;
        $minimum = $maximumIntervals ? $this->unionYears($minimumIntervals) : null;
        $maximum = $maximumIntervals ? $this->unionYears($maximumIntervals) : null;
        $source = $maximumIntervals ? 'employment dates' : null;
        if ($years === null && preg_match($pattern, $normalise($resume), $claim)) {
            $years = (float) $claim[1];
            $source = 'a statement in your CV';
        }
        $shortfall = $required !== null && ($maximum ?? $years) !== null && ($maximum ?? $years) < $required;
        $requiredDisplay = $required.' '.($required == 1 ? 'year' : 'years');
        $message = $required === null ? 'No explicit minimum number of years was detected in the job description.'
            : ($years === null ? "The job mentions {$requiredDisplay} of experience. We could not estimate your experience from the CV; check your employment dates."
                : "The job mentions {$requiredDisplay} of experience; your CV shows approximately {$years} years in total, based on {$source}.".($shortfall ? ' Your CV shows fewer years than requested.' : ' Check that these years are relevant to the required role.'));

        if ($yearOnly) {
            $message .= " Year-only dates do not establish exact duration; the possible calendar range is {$minimum}–{$maximum} years. Add employment months to confirm it.";
        }
        return ['calculation_source' => $maximumIntervals ? 'employment_dates' : ($years !== null ? 'cv_statement' : 'unknown'),
            'minimum_calendar_years' => $minimum, 'maximum_calendar_years' => $maximum,
            'date_precision' => $yearOnly ? 'year' : ($maximumIntervals ? 'month_or_day' : 'unknown'), 'periods' => $periods,
            'required_years' => $required, 'cv_years' => $years, 'shortfall' => $shortfall, 'requirement' => $evidence, 'message' => $message, 'unreadable_dates' => array_values(array_unique($unreadable)),
            'note' => 'Calendar tenure is not confirmed full-time or role-relevant experience. Overlapping periods count once. Freelance and attachment periods remain identified separately; academic projects, education and in-progress certifications are not employment.'];
    }

    private function date(string $value, bool $end): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^(present|current|now|ongoing|to date)$/i', $value)) {
            return CarbonImmutable::now('Africa/Johannesburg')->startOfDay()->addDay();
        }
        if (preg_match('/^\d{4}$/', $value)) {
            // A year alone is not evidence of a complete January–December year.
            return CarbonImmutable::create((int) $value, 1, 1);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Invalid date');
            return $end ? $date->addDay() : $date;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $full)) {
            $date = CarbonImmutable::createSafe((int) $full[3], (int) $full[2], (int) $full[1]);
            return $end ? $date->addDay() : $date;
        }
        if (preg_match('/^\d{1,2}\s+[a-z]+\.?\s+\d{4}$/i', $value)) {
            $date = CarbonImmutable::parse($value)->startOfDay();
            return $end ? $date->addDay() : $date;
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

    private function unionYears(array $intervals): float
    {
        usort($intervals, fn ($left, $right) => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) $merged[$last][1] = $end->max($merged[$last][1]);
            else $merged[] = [$start, $end];
        }
        $days = array_sum(array_map(fn ($range) => $range[0]->diffInDays($range[1]), $merged));
        return round($days / 365.2425, 1);
    }
}
