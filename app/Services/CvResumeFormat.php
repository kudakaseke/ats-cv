<?php

namespace App\Services;

class CvResumeFormat
{
    public static function strengths(array|string|null ...$lists): array
    {
        $result = [];
        foreach ($lists as $list) {
            foreach (is_array($list) ? $list : preg_split('/[,;\r\n|]+/u', $list ?? '') as $item) {
                $item = preg_replace('/^[\s\x{2022}*|]+/u', '', trim($item));
                if ($item !== '' && ! isset($result[mb_strtolower($item)])) {
                    $key = (new CvCompetencyVocabulary)->key($item);
                    $result[$key] ??= $item;
                }
            }
        }

        return array_values($result);
    }

    public static function references(?string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text ?? '') as $line) {
            $parts = array_map(fn ($part) => trim(preg_replace('/[\t ]+/u', ' ', $part)), explode('|', trim($line)));
            $line = implode(' | ', array_filter($parts, fn ($part) => $part !== ''));
            if ($line !== '' && ! in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    public static function builder(array $data): array
    {
        // Recognise behavioural items even when a source CV uses a broad
        // Skills heading. Preserve technical phrases and grouped categories.
        $behavioural = [];
        $technicalLines = [];
        $hasGroups = str_contains($data['skills'] ?? '', ':');
        foreach (preg_split('/\R/u', $data['skills'] ?? '') as $line) {
            $label = '';
            if (preg_match('/^\s*([^:]+):\s*(.*)$/u', $line, $parts)) {
                $label = trim($parts[1]);
                $line = $parts[2];
            }
            $technical = [];
            foreach (preg_split('/[,;|](?![^()]*\))/u', $line) as $skill) {
                $skill = trim($skill);
                if ((new CvCompetencyVocabulary)->canonical($skill) !== null) {
                    $behavioural[] = $skill;
                } elseif ($skill !== '') {
                    $technical[] = $skill;
                }
            }
            if ($technical) $technicalLines[] = ($label !== '' ? $label.': ' : '').implode(', ', array_unique($technical));
        }
        $data['skills'] = implode($hasGroups ? "\n" : ', ', $technicalLines);
        $data['core_strengths'] = implode(', ', self::strengths($data['core_strengths'] ?? '', $data['competencies'] ?? '', $behavioural));
        $data['competencies'] = '';
        if (str_contains($data['skills'] ?? '', ':')) {
            $data['skills'] = (new CvTechnicalSkills)->format($data['skills'], $data['role'] ?? '');
        }
        if (($data['template'] ?? null) === 'tailored-reference') {
            foreach (['summary' => 'Professional Summary', 'experience' => 'Experience', 'skills' => 'Technical Skills'] as $field => $title) {
                $old = $data['section_titles'][$field] ?? '';
                if ($old === '' || preg_match('/^(summary|professional summary|relevant experience|professional experience|experience|speciali[sz]ed skills|technical skills)$/i', $old)) {
                    $data['section_titles'][$field] = $title;
                }
            }
        }
        $data['references'] = self::references($data['references'] ?? '');

        return $data;
    }
}
