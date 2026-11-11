<?php

namespace App\Services;

class CvTextParser
{
    public function parse(string $text): array
    {
        $data = [];
        $aliases = [
            'summary' => ['summary', 'professional summary', 'personal profile', 'profile', 'career objective', 'objective', 'about me'],
            'experience' => ['experience', 'work experience', 'professional experience', 'employment history', 'employment', 'career history', 'work history', 'professional background', 'professional experiences', 'working experience', 'employment record'],
            'education' => ['education', 'qualifications', 'academic qualifications', 'education and qualifications', 'educational background'],
            'skills' => ['skills', 'technical skills', 'professional skills', 'specialized skills', 'specialised skills', 'technical and professional skills'],
            'competencies' => ['key competency', 'key competencies', 'core competencies', 'competencies', 'areas of expertise', 'key competence', 'key competences', 'core competences', 'competences'],
            'core_strengths' => ['core strength', 'core strengths', 'key strength', 'key strengths', 'personal strengths', 'strengths', 'strengths and competencies', 'strengths/competencies', 'core strengths/key competences', 'core strengths/key competencies', 'core strengths and key competences', 'strengths and competences', 'strengths/competences'],
            'references' => ['references', 'referees', 'professional references'],
            'achievements' => ['achievements', 'key achievements', 'awards'],
            'courses' => ['courses', 'training', 'certifications', 'training and certifications', 'certification', 'certificates'],
            'languages' => ['languages'],
            'projects' => ['projects', 'personal projects', 'selected projects'],
            'volunteering' => ['volunteering', 'volunteer experience', 'voluntary work'],
            'publications' => ['publications'],
            'interests' => ['interests', 'hobbies', 'hobbies and interests'],
        ];
        $sections = [];
        $current = null;
        $header = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($current !== null) {
                    $sections[$current][] = '';
                }

                continue;
            }
            $line = preg_replace('/^\s*\d+[.)]\s*/u', '', $line);
            $parts = preg_split('/:\s*/u', $line, 2);
            $heading = mb_strtolower(trim(preg_replace('/^[\s\x{2022}-]+|[\s:]+$/u', '', $parts[0])));
            $heading = preg_replace('/\s*\/\s*/u', '/', $heading);
            $matched = null;
            foreach ($aliases as $key => $names) {
                if (in_array($heading, $names, true)) {
                    $matched = $key;
                    break;
                }
            }
            if ($matched !== null) {
                $current = $matched;
                $sections[$current] ??= [];
                if (! empty($parts[1])) {
                    $sections[$current][] = $parts[1];
                }
            } elseif ($current === null && preg_match('/^(?:full name|name)\s*:\s*(.+)$/iu', $line, $name)) {
                $data['name'] = mb_substr($name[1], 0, 150);
            } elseif ($current !== null) {
                $sections[$current][] = $line;
            } else {
                $header[] = $line;
            }
        }
        $contactText = implode("\n", $header);
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $contactText, $email);
        $data['email'] = $email[0] ?? '';
        preg_match('/(?<![\d\w])(?:\+\d{1,3}[ .-]?)?(?:\(\d{2,4}\)|\d{2,4})[ .-]?\d{3}[ .-]?\d{4}(?!\d)/', $contactText, $phone);
        $data['phone'] = $phone[0] ?? '';
        if (empty($data['name'])) {
            foreach (array_slice($header, 0, 6) as $line) {
                if (preg_match('/^(?:curriculum vitae|resume|cv|personal details|contact details)$/iu', $line)) {
                    continue;
                }
                if (preg_match("/^[\p{L}][\p{L} .\x{2019}'-]{2,149}$/u", $line) && count(preg_split('/\s+/u', $line)) >= 2) {
                    $data['name'] = $line;
                    break;
                }
            }
        }
        foreach (['summary' => 5000, 'skills' => 2000, 'competencies' => 5000, 'core_strengths' => 5000, 'references' => 5000] as $key => $limit) {
            if (isset($sections[$key])) {
                $data[$key] = mb_substr(trim(implode("\n", $sections[$key])), 0, $limit);
            }
        }
        foreach (['experience', 'education'] as $key) {
            if (! isset($sections[$key])) {
                continue;
            }
            $blocks = [];
            $block = [];
            $primarySeen = false;
            foreach ($sections[$key] as $line) {
                $startsEntry = preg_match($key === 'experience' ? '/^(?:position|job title|title)\s*:/iu' : '/^(?:degree|qualification)\s*:/iu', $line);
                if (($line === '' || ($startsEntry && $primarySeen)) && $block) {
                    $blocks[] = implode("\n", $block);
                    $block = [];
                    $primarySeen = false;
                }
                if ($line !== '') {
                    $block[] = $line;
                }
                if ($startsEntry) {
                    $primarySeen = true;
                }
            }
            if ($block) {
                $blocks[] = implode("\n", $block);
            }
            $data[$key] = [];
            foreach (array_slice($blocks, 0, 20) as $block) {
                $lines = array_values(array_filter(explode("\n", $block), fn ($line) => trim($line) !== ''));
                if (! $lines) {
                    continue;
                }
                $row = $key === 'experience' ? ['title' => '', 'company' => '', 'period' => '', 'location' => '', 'description' => ''] : ['degree' => '', 'school' => '', 'period' => '', 'location' => '', 'grade' => ''];
                $remaining = [];
                foreach ($lines as $line) {
                    if (preg_match('/^(?:period|dates?)\s*:\s*(.+)$/iu', $line, $labelledPeriod)) {
                        $row['period'] = trim($labelledPeriod[1]);

                        continue;
                    }
                    // Keep the role or qualification when dates share the same line.
                    $datePattern = CvExperienceMatcher::periodPattern();
                    if (! $row['period'] && ! preg_match('/^(?:[\x{2022}*\-]|responsibilities:|duties:)/iu', $line) && preg_match($datePattern, $line, $date)) {
                        $row['period'] = $date[0];
                        $line = trim(str_replace($date[0], '', $line), " \t|,-");
                        if ($line === '' || preg_match('/^(?:period|dates?)\s*:$/iu', $line)) {
                            continue;
                        }
                    }

                    if (preg_match('/^(position|job title|title|company|employer|place|location|period|dates?|degree|qualification|grade|degree class\/grade|degree class|classification|gpa|school|institution|university|responsibilities|responsibilties|duties)\s*:\s*(.+)$/iu', $line, $m)) {
                        $field = match (mb_strtolower($m[1])) {
                            'position', 'job title', 'title' => 'title', 'company', 'employer', 'place' => 'company',
                            'date', 'dates', 'period' => 'period', 'degree', 'qualification' => 'degree',
                            'grade', 'degree class/grade', 'degree class', 'classification', 'gpa' => 'grade',
                            'school', 'institution', 'university' => 'school', 'location' => 'location', default => 'description',
                        };
                        if (array_key_exists($field, $row)) {
                            $row[$field] .= ($row[$field] ? "\n" : '').$m[2];
                        } else {
                            $remaining[] = $line;
                        }
                    } else {
                        $remaining[] = $line;
                    }
                }
                $primary = $key === 'experience' ? 'title' : 'degree';
                $secondary = $key === 'experience' ? 'company' : 'school';
                if (! $row[$primary] && $remaining) {
                    $first = array_shift($remaining);
                    $pair = preg_split('/\s+(?:at|@|\|)\s+/iu', $first, 2);
                    $row[$primary] = $pair[0];
                    if (isset($pair[1]) && ! $row[$secondary]) {
                        $row[$secondary] = $pair[1];
                    }
                }
                if (! $row[$secondary] && $remaining && ! preg_match('/^[\x{2022}*\-]/u', $remaining[0])) {
                    $row[$secondary] = array_shift($remaining);
                }
                if ($key === 'experience') {
                    $row['description'] = trim($row['description']."\n".implode("\n", $remaining));
                } elseif ($remaining) {
                    $row['school'] .= "\n".implode("\n", $remaining);
                }
                foreach ($row as $field => $value) {
                    $row[$field] = mb_substr($value, 0, $field === 'description' ? 5000 : (in_array($field, ['period', 'location']) ? 150 : 200));
                }
                $last = count($data[$key]) - 1;
                $otherFields = array_filter($row, fn ($value, $field) => $field !== 'period' && trim($value) !== '', ARRAY_FILTER_USE_BOTH);
                if ($row['period'] && ! $otherFields && $last >= 0 && ! $data[$key][$last]['period']) {
                    $data[$key][$last]['period'] = $row['period'];
                } else {
                    $data[$key][] = $row;
                }
            }
        }
        foreach (['achievements', 'courses', 'languages', 'projects', 'volunteering', 'publications', 'interests'] as $key) {
            if (empty($sections[$key])) {
                continue;
            }
            $content = trim(implode("\n", $sections[$key]));
            if (! $content) {
                continue;
            }
            $data['custom_sections'][] = ['title' => ucfirst($key), 'content' => mb_substr($content, 0, 5000)];
        }

        return $data;
    }
}
