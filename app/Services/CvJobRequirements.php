<?php

namespace App\Services;

class CvJobRequirements
{
    public const VERSION = 'weighted-requirements-v5';
    public const WEIGHTS = ['mandatory' => 3, 'required' => 2, 'preferred' => 1];

    public function extract(string $job, CvKeywordMatcher $matcher): array
    {
        $category = null;
        $importance = 'required';
        $excluded = false;
        $items = [];
        $job = preg_replace('/\b((?:(?:mandatory|required|essential|minimum|key|technical|core|preferred|desirable|optional)\s+)?(?:qualifications|skills|competencies|strengths|requirements)|mandatory|preferred|desirable|optional)\s*:/iu', "\n$1:", $job);
        foreach (preg_split('/\R/u', $job) as $line) {
            $line = trim(preg_replace('/^\s*(?:\d+[.)]|[\x{2022}\x{25AA}\x{25CF}\x{25E6}\x{2023}\x{25A0}*\-])\s*/u', '', $line), " \t#*");
            if (preg_match('/^(?:about the role|purpose of (?:the )?(?:position|role)|full job description)\s*:?$/iu', $line)) {
                $category = null;
                $excluded = true;
                continue;
            }
            if (preg_match('/^key performance areas\s*:?$/iu', $line)) {
                $category = 'skills';
                $importance = 'required';
                $excluded = false;
                continue;
            }
            if (preg_match('/^(?:(?:key|main|core|specific)\s+)?(?:closing date|application deadline|applications close|job reference|requisition id|benefits|company overview|company description|about us|about the company|about the employer|who we are|our mission|how to apply|application process|application instructions|applications (?:must|should|close)|apply now|submit your|send your|contact details|equal opportunit\w*|salary|location|employment type|what we offer|responsibilities|duties|study diplomacy|area\(s\) of expertise|nationality|assignment requirements)\b/iu', $line)) {
                $category = null;
                $excluded = true;
                continue;
            }
            if (preg_match('/^(?:(mandatory|required|essential|minimum|preferred|desirable|optional|key|technical|core)\s+)?(qualifications?|education|skills?|competencies|competences|strengths|personal attributes|personal qualities|personal strengths|core attributes|competencies and values|strengths\s*(?:and|&|or)\s*competencies|competencies\s*(?:and|&|or)\s*strengths)(?:\s+required)?\s*(?::\s*(.*))?$/iu', $line, $heading)) {
                $category = preg_match('/^(?:qualifications?|education)$/i', $heading[2]) ? 'qualifications' : (preg_match('/^skills?$/i', $heading[2]) ? 'skills' : 'competencies');
                $importance = $this->importance($heading[1] ?? '', 'required');
                $line = trim($heading[3] ?? '');
            } elseif (preg_match('/^(?:(minimum|key|essential|required|mandatory|preferred|desirable|optional)\s+)?(?:requirements|qualifications\s*(?:and|&)\s*experience|experience\s*(?:and|&)\s*qualifications|skills\s*(?:and|&)\s*experience|person specification|essential criteria|what you bring|what we are looking for|about you)\s*(?::\s*(.*))?$/iu', $line, $heading)) {
                $category = 'general';
                $importance = $this->importance($heading[1] ?? '', 'required');
                $line = trim($heading[2] ?? '');
            } elseif (preg_match('/^(mandatory|required|essential|preferred|desirable|optional)\s*:\s*(.*)$/iu', $line, $heading)) {
                $category = 'general';
                $importance = $this->importance($heading[1], 'required');
                $line = trim($heading[2]);
            } elseif (preg_match('/^(?:required education level|education level)\s*:\s*(.*)$/iu', $line, $heading)) {
                $category = 'qualifications';
                $importance = str_starts_with(strtolower($line), 'required') ? 'mandatory' : 'required';
                $line = trim($heading[1]);
            }
            if (preg_match('/^(?:experience|relevant experience|required experience)\s*:?$/iu', $line)) {
                $category = null;
                $excluded = true;
                continue;
            }
            if ($line === '') continue;
            if ($category !== null) $excluded = false;
            // Without a requirements section, only explicit requirement statements
            // are eligible. Never turn all company prose into scored keywords.
            $lineCategory = $category;
            if ($lineCategory === null) {
                if ($excluded || ! preg_match('/\b(?:must|requires?|required|essential|mandatory|preferred|desirable|proficiency in|knowledge of|ability to|experience (?:in|with)|bachelor|diploma|certification|licen[cs]e)\b/iu', $line)) continue;
                $lineCategory = 'general';
            }
            $clauses = [];
            foreach (preg_split('/;|(?<=[.!?])\s+(?=[A-Z])/u', $line) as $sentence) {
                $hasItemModifiers = preg_match('/\b(?:required|mandatory|essential|preferred|desirable|optional)\b/iu', $sentence);
                $keepProse = $lineCategory === 'qualifications' || preg_match('/\b(?:degree|diploma|bachelor|including|such as|or)\b/iu', $sentence);
                $clauses = [...$clauses, ...($hasItemModifiers && ! $keepProse ? preg_split('/,\s*/u', $sentence) : [$sentence])];
            }
            foreach ($clauses as $clause) {
                if (preg_match('/\b(?:\d+|\w+\s*\(\d+\))\s*\+?\s*years?\b.*\bexperience\b/iu', $clause)) continue;
                $itemImportance = $this->importance($clause, $importance);
                // Employers often put skills under a broad "Qualifications"
                // heading. Do not limit those skills to the CV's education text.
                $clauseCategory = $lineCategory;
                if ($clauseCategory === 'qualifications' && preg_match('/^(?:sound\s+)?(?:knowledge|proficiency|experience|ability|familiarity)\b/iu', trim($clause))) {
                    $clauseCategory = 'skills';
                }
                $clause = preg_replace('/^(?:(?:candidates?|applicants?)\s+(?:must|should)\s+(?:have|hold)|must\s+(?:have|hold)|we\s+require|(?:proficiency|knowledge|experience)\s+(?:in|of|with)|(?:required|mandatory|essential|preferred|desirable|optional)\s*:)\s*/iu', '', trim($clause));
                $clause = preg_replace('/\s*(?:\((?:required|mandatory|essential|preferred|desirable|optional)\)|(?:is |are )?(?:required|mandatory|essential|preferred|desirable|optional|advantageous)|is an asset)\s*[.!]?$/iu', '', $clause);
                $clause = preg_replace('/^professional certifications?\s+(?:such as|including)\s+(.+?)\s+(?:are|is|will be)\s+(?:an?\s+)?added advantage[.!]?$/iu', '$1', $clause);
                $actualCategory = $clauseCategory === 'general' ? (preg_match('/\b(?:degree|bachelor|bachelors|bcom|bsc|diploma|certificate|certification|certified|registered|registration|licen[cs]e|grade|masters?|doctorate|phd|qualification)\b/iu', $clause) ? 'qualifications' : (preg_match('/\b(?:teamwork|integrity|adaptability|resilience|attention to detail|initiative|strengths?|competenc)/iu', $clause) ? 'competencies' : 'skills')) : $clauseCategory;
                foreach ((new CvRequirementPhrases)->split($clause, $actualCategory) as $phrase) {
                    $phrase = preg_replace('/^[\s.:\-\x{2022}*]+|[\s.:\-\x{2022}*]+$/u', '', $phrase);
                    $terms = $matcher->tokens($phrase);
                    if (! $terms) continue;
                    $compact = ! preg_match('/\bor\b/i', $phrase) && count(array_filter($terms, fn ($term) => preg_match('/^(?:PHP|HTML|CSS|JavaScript|TypeScript|SQL|MySQL|Python|Java|C#|C\+\+|\.NET|Excel|Word|AWS|Git)$/i', $term))) === count($terms);
                    foreach ($compact ? array_values($terms) : [$phrase] as $label) {
                        $keys = array_keys($matcher->tokens($label));
                        sort($keys);
                        $key = implode(' ', $keys);
                        $weight = self::WEIGHTS[$itemImportance];
                        if (! isset($items[$key]) || $weight > $items[$key]['weight']) {
                            $items[$key] = ['category' => $actualCategory, 'requirement' => $label, 'importance' => $itemImportance, 'weight' => $weight];
                        }
                    }
                }
            }
        }
        return array_slice(array_values($items), 0, 100);
    }

    private function importance(string $text, string $default): string
    {
        if (preg_match('/\b(?:must|mandatory|essential|requires?|required|minimum)\b/iu', $text)) return 'mandatory';
        if (preg_match('/\b(?:preferred|preferable|desirable|optional|advantageous|added advantage|nice.to.have)\b|\b(?:is|be)\s+an\s+asset\b/iu', $text)) return 'preferred';
        return $default;
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
        $matchedWeight = 0;
        $totalWeight = 0;
        foreach ($items as $item) {
            $item['importance'] ??= 'required';
            $item['weight'] = self::WEIGHTS[$item['importance']] ?? 2;
            $totalWeight += $item['weight'];
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
                $matchedWeight += $item['weight'];
            } else {
                $missing[] = $item['requirement'];
            }
        }

        $baseScore = min($missing ? 99 : 100, $totalWeight ? (int) round($matchedWeight / $totalWeight * 100) : 0);
        $score = $baseScore;

        return ['matched' => $matched, 'missing' => $missing, 'requirement_groups' => $groups,
            'confirmable_skills' => array_values(array_map(fn ($item) => $item['requirement'], array_filter($groups['skills'] ?? [], fn ($item) => ! $item['matched'] && mb_strlen($item['requirement']) <= 200))),
            'total' => count($items), 'keyword_score' => $score, 'base_requirement_score' => $baseScore,
            'certificate_bonus' => 0, 'matched_weight' => $matchedWeight, 'total_weight' => $totalWeight, 'green_flags' => array_values(array_unique($greenFlags)),
            'scoring_basis' => self::VERSION];
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
