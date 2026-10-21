<?php

namespace App\Services;

class CvJobRequirements
{
    public function extract(string $job, CvKeywordMatcher $matcher): array
    {
        $category = null;
        $items = [];
        $job = preg_replace('/\b(Qualifications|Skills|Competencies|Strengths)\s*:/iu', "\n$1:", $job);
        foreach (preg_split('/\R/u', $job) as $line) {
            $line = trim(preg_replace('/^\s*(?:\d+[.)]|[•*\-])\s*/u', '', $line), " \t#*");
            if (preg_match('/^(?:Required education level|Education level)\s*:\s*(.*)$/iu', $line, $special)) {
                $category = 'qualifications';
                $line = trim($special[1]);
            } elseif (preg_match('/^Competencies and values\s*:\s*(.*)$/iu', $line, $special)) {
                $category = 'competencies';
                $line = trim($special[1]);
            } elseif (preg_match('/^(?:Study Diplomacy|Area\(s\) of expertise|Driving licen[cs]e|Languages|Nationality|Eligibility criteria|Assignment requirements|Relevant experience|Required experience)\s*:?$/iu', $line)) {
                $category = null;

                continue;
            }
            if (preg_match('/^(?:preferred|desirable|optional)(?:\s+(?:qualifications|skills|competencies|requirements))?\s*:?$/iu', $line)) {
                $category = null;

                continue;
            } elseif (preg_match('/^(?:(?:minimum|key|essential)\s+)?(?:requirements|qualifications\s*(?:and|&)\s*experience|experience\s*(?:and|&)\s*qualifications|skills\s*(?:and|&)\s*experience)\s*(?::\s*(.*))?$/iu', $line, $general)) {
                $category = 'general';
                $line = trim($general[1] ?? '');
            } elseif (preg_match('/^(?:(?:required|key|essential|technical|core|minimum)\s+)?(qualifications?|education|skills?|competencies|competences|strengths|strengths\s*(?:and|&|or)\s*competencies|competencies\s*(?:and|&|or)\s*strengths)(?:\s+required)?\s*(?::\s*(.*))?$/iu', $line, $heading)) {
                $category = match (strtolower($heading[1])) {
                    'qualification', 'qualifications', 'education' => 'qualifications',
                    'skill', 'skills' => 'skills',
                    default => 'competencies',
                };
                $line = trim($heading[2] ?? '');
            } elseif (preg_match('/^(?:experience|responsibilities|duties|benefits|about us|about the company|how to apply|salary|what we offer)\s*:?(?:\s.*)?$/iu', $line)) {
                $category = null;
            }
            if ($category === null || $line === '') {
                continue;
            }
            $lineCategory = $category;
            if ($category === 'general') {
                if (preg_match('/\b\d+\s*\+?\s*years?\b.*\bexperience\b/iu', $line)) {
                    continue;
                }
                $lineCategory = preg_match('/\b(?:degree|bachelor|bachelors|bcom|bsc|diploma|certificate|certification|certified|registered|registration|licen[cs]e|licensed|grade|masters?|doctorate|phd|qualification)\b/iu', $line) ? 'qualifications'
                    : (preg_match('/\b(?:teamwork|integrity|adaptability|resilience|attention to detail|initiative|strengths?|competenc)/iu', $line) ? 'competencies' : 'skills');
            }
            foreach ((new CvRequirementPhrases)->split($line, $lineCategory) as $phrase) {
                $phrase = trim($phrase, " \t.:-•*");
                $phrase = preg_replace('/^(?:(?:minimum|at least)\s+)?\d+\s*\+?\s*years?\s*(?:of\s+)?(?:relevant\s+)?experience\s*(?:(?:in|with|of)\s+)?/iu', '', $phrase);
                $terms = $matcher->tokens($phrase);
                if (! $terms) {
                    continue;
                }
                // Compact lists of technology names remain individual requirements.
                $compact = ! preg_match('/\bor\b/i', $phrase) && count($terms) > 0 && count(array_filter($terms, fn ($term) => preg_match('/^(?:PHP|HTML|CSS|JavaScript|TypeScript|SQL|MySQL|Python|Java|C#|C\+\+|\.NET|Excel|Word|AWS|Git)$/i', $term))) === count($terms);
                foreach ($compact ? array_values($terms) : [$phrase] as $label) {
                    $key = implode(' ', array_keys($matcher->tokens($label)));
                    $items[$key] = ['category' => $lineCategory, 'requirement' => $label];
                }
            }
        }

        return array_slice(array_values($items), 0, 100);
    }

    public function compare(array $items, string $resume, CvKeywordMatcher $matcher): array
    {
        $parsed = (new CvTextParser)->parse($resume);
        $education = implode("\n", array_map(fn ($row) => implode(' ', $row), $parsed['education'] ?? []));
        foreach ($parsed['custom_sections'] ?? [] as $section) {
            if (in_array(strtolower($section['title']), ['courses', 'certifications', 'certificates', 'training'], true)) {
                $education .= "\n".$section['content'];
            }
        }
        $groups = [];
        $matched = [];
        $missing = [];
        $greenFlags = [];
        foreach ($items as $item) {
            $alternatives = preg_split('/\s+or\s+/iu', $item['requirement']);
            if ($item['category'] === 'qualifications' && (count($alternatives) > 1 || str_contains($item['requirement'], ','))) {
                $alternatives = preg_split('/\s+or\s+|,\s*/iu', $item['requirement']);
            }
            $prefix = $item['category'] === 'qualifications' ? array_intersect_key($matcher->tokens($alternatives[0]), array_flip(['bachelor', 'degree', 'diploma', 'certificate', 'master', 'doctorate'])) : [];
            $source = $item['category'] === 'qualifications' && $education !== '' ? $education : $resume;
            $found = false;
            foreach (preg_split('/\R/u', $source) as $line) {
                if (preg_match('/\b(?:no|not|without|lack|lacking|missing|do not have|don.t have)\b/i', $line)) {
                    continue;
                }
                if ($item['category'] === 'qualifications' && preg_match('/\b(?:seeking|pursuing|working towards|studying|candidate|enrolled|in progress|preparation|preparatory)\b/i', $line)) {
                    continue;
                }
                $available = $matcher->tokens($line);
                if ($item['category'] === 'qualifications' && preg_match('/\b(?:psychology|sociology|political science|anthropology)\b/iu', $line)) {
                    $available = [...$available, ...$matcher->tokens('Social Sciences')];
                }
                foreach ($alternatives as $index => $alternative) {
                    if (preg_match('/\b(?:equivalent|related)\b/iu', $alternative)) {
                        continue;
                    }
                    $needed = [...$prefix, ...$matcher->tokens($alternative)];
                    if ($item['category'] === 'qualifications') {
                        $requiredLevel = $this->academicLevel($alternative);
                        $cvLevel = $this->academicLevel($line);
                        if ($requiredLevel >= 2 && $cvLevel > $requiredLevel && ! preg_match('/\b(?:only|exactly|specifically)\b/i', $alternative)) {
                            $subject = array_diff_key($needed, array_flip(['bachelor', 'degree', 'diploma', 'national', 'master', 'masters', 'doctorate', 'phd']));
                            if (! array_diff_key($subject, $available)) {
                                $greenFlags[] = 'Higher relevant qualification: '.$line.' meets the academic level requested in '.$item['requirement'].'.';
                                $found = true;
                                break 2;
                            }
                        }
                        preg_match_all('/\b\d+(?:\.\d+)?\b/u', $alternative, $numbers);
                        if (array_filter($numbers[0], fn ($number) => ! preg_match('/\b'.preg_quote($number, '/').'\b/u', $line))) {
                            continue;
                        }
                    }
                    if ($needed && ! array_diff_key($needed, $available)) {
                        $found = true;
                        break 2;
                    }
                }
            }
            $groups[$item['category']][] = [...$item, 'matched' => $found];
            if ($found) {
                $matched[] = $item['requirement'];
            } else {
                $missing[] = $item['requirement'];
            }
        }

        $baseScore = count($items) ? (int) round(count($matched) / count($items) * 100) : 0;
        $certificates = [];
        $jobTerms = $matcher->tokens(implode(' ', array_column($items, 'requirement')));
        $generic = array_flip(['certificate', 'certification', 'certified', 'professional', 'degree', 'bachelor', 'diploma', 'national', 'course', 'training', 'completion']);
        foreach ($parsed['custom_sections'] ?? [] as $section) {
            if (! in_array(strtolower($section['title']), ['courses', 'certifications', 'certificates', 'training'], true)) {
                continue;
            }
            foreach (preg_split('/\R/u', $section['content']) as $line) {
                $line = preg_split('/\s+(?:-|\|)\s+/u', $line, 2)[0];
                if (preg_match('/\b(?:no|not|pursuing|studying|expired|university|college|institute|provider)\b/i', $line)) {
                    continue;
                }
                $terms = array_diff_key($matcher->tokens($line), $generic);
                if (! $terms || ! array_intersect_key($terms, array_diff_key($jobTerms, $generic))) {
                    continue;
                }
                $alreadyRequired = false;
                foreach ($groups['qualifications'] ?? [] as $qualification) {
                    $requiredTerms = $matcher->tokens($qualification['requirement']);
                    if ($qualification['matched'] && ! array_diff_key($requiredTerms, $matcher->tokens($line))) {
                        $alreadyRequired = true;
                    }
                }
                if (! $alreadyRequired) {
                    $certificates[implode(' ', array_keys($terms))] = trim($line);
                }
            }
        }
        $ceiling = $missing ? 99 : 100;
        $bonus = min(6, count($certificates) * 2, max(0, $ceiling - $baseScore));
        $score = min($ceiling, $baseScore + $bonus);
        foreach ($certificates as $certificate) {
            $greenFlags[] = 'Additional relevant certificate: '.$certificate;
        }

        return ['matched' => $matched, 'missing' => $missing, 'requirement_groups' => $groups,
            'confirmable_skills' => array_values(array_map(fn ($item) => $item['requirement'], array_filter($groups['skills'] ?? [], fn ($item) => ! $item['matched'] && mb_strlen($item['requirement']) <= 200))),
            'total' => count($items), 'keyword_score' => $score, 'base_requirement_score' => $baseScore,
            'certificate_bonus' => $bonus, 'green_flags' => array_values(array_unique($greenFlags)),
            'scoring_basis' => 'requirements-v2'];
    }

    private function academicLevel(string $value): int
    {
        return match (true) {
            (bool) preg_match('/\b(?:phd|doctorate|doctoral)\b/i', $value) => 5,
            (bool) preg_match('/\bmasters?\b/i', $value) => 4,
            (bool) preg_match('/\b(?:bachelor|bachelors|bcom|bsc|degree)\b/i', $value) => 3,
            (bool) preg_match('/\bdiploma\b/i', $value) => 2,
            default => 0,
        };
    }
}
