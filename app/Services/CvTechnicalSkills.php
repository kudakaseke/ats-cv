<?php

namespace App\Services;

class CvTechnicalSkills
{
    private const ORDERS = [
        'software' => ['Programming', 'Frameworks', 'Databases', 'Tools', 'Methods'],
        'accounting' => ['Technical', 'Compliance', 'Leadership'],
        'engineering' => ['Design', 'Tools', 'Standards', 'Methods', 'Safety'],
        'management' => ['Strategy', 'Operations', 'Delivery', 'Tools', 'Governance'],
    ];

    public function format(string $skills, string $profession = '', ?string $source = null): string
    {
        if (trim($skills) === '') return '';
        if ($source !== null) {
            // Employment dates do not prove the duration or proficiency of an
            // individual tool. Retain such annotations only when supplied.
            $skills = preg_replace_callback('/[^,:\r\n]+\((?:\d+(?:\.\d+)?\+?\s*(?:years?|months?)(?:\s+(?:of\s+)?experience)?|beginner|intermediate|advanced|expert|proficient|basic)\)/iu', function ($match) use ($source) {
                $claim = trim($match[0]);
                return mb_stripos(preg_replace('/\s+/u', ' ', $source), preg_replace('/\s+/u', ' ', $claim)) !== false
                    ? $match[0] : preg_replace('/\s*\([^()]+\)$/u', '', $match[0]);
            }, $skills);
        }
        $profile = match (true) {
            (bool) preg_match('/accountant|accounting|auditor|financial controller|finance manager/iu', $profession) => 'accounting',
            (bool) preg_match('/software|developer|programmer|web development|data scien/iu', $profession) => 'software',
            (bool) preg_match('/engineer/iu', $profession) => 'engineering',
            (bool) preg_match('/manager|director|executive/iu', $profession) => 'management',
            default => null,
        };
        $groups = [];
        $ungrouped = [];
        foreach (preg_split('/\R/u', $skills) as $line) {
            if (preg_match('/^\s*([^:]+):\s*(.+)$/u', $line, $parts)) {
                $label = $this->label(trim($parts[1]));
                $key = mb_strtolower($label);
                $groups[$key]['label'] ??= $label;
                $groups[$key]['items'] = [...($groups[$key]['items'] ?? []), ...$this->items($parts[2])];
            } else {
                $ungrouped = [...$ungrouped, ...$this->items($line)];
            }
        }
        // Explicit source categories are stronger evidence than a guessed industry.
        if ($profile === null && (isset($groups['programming']) || isset($groups['frameworks']) || isset($groups['databases']))) $profile = 'software';
        if ($profile === null && isset($groups['technical'], $groups['compliance'])) $profile = 'accounting';
        if (! $groups && $profile === null) return $skills;
        foreach ($ungrouped as $item) {
            $label = $this->category($item, $profile);
            $key = mb_strtolower($label);
            $groups[$key]['label'] ??= $label;
            $groups[$key]['items'][] = $item;
        }
        // Correct recognisable software items even when a provider put them in
        // the wrong labelled row. Unknown domain categories remain intact.
        if ($profile === 'software') {
            $corrected = [];
            foreach ($groups as $group) {
                foreach ($group['items'] as $item) {
                    $detected = $this->category($item, 'software');
                    $label = $detected !== 'Tools' ? $detected : $group['label'];
                    $key = mb_strtolower($label);
                    $corrected[$key]['label'] ??= $label;
                    $corrected[$key]['items'][] = $item;
                }
            }
            $groups = $corrected;
        }
        $ordered = [];
        foreach (self::ORDERS[$profile] ?? [] as $label) {
            $key = mb_strtolower($label);
            if (isset($groups[$key])) {
                $ordered[$key] = $groups[$key];
                unset($groups[$key]);
            }
        }
        $lines = [];
        $seen = [];
        foreach ([...$ordered, ...$groups] as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $key = mb_strtolower(preg_replace('/\s*\((?:\d+(?:\.\d+)?\+?\s*(?:years?|months?)(?:\s+(?:of\s+)?experience)?|beginner|intermediate|advanced|expert|proficient|basic)\)$/iu', '', $item));
                if (! isset($seen[$key])) {
                    $items[] = $item;
                    $seen[$key] = true;
                }
            }
            if ($items) $lines[] = $group['label'].': '.implode(', ', $items);
        }
        return implode("\n", $lines);
    }

    private function label(string $label): string
    {
        return match (mb_strtolower($label)) {
            'programming', 'programming languages', 'languages' => 'Programming',
            'framework', 'frameworks' => 'Frameworks',
            'database', 'databases', 'database systems', 'database management systems' => 'Databases',
            'tool', 'tools', 'tools and technologies', 'tools & technologies' => 'Tools',
            'method', 'methods', 'methodologies' => 'Methods',
            default => $label,
        };
    }

    private function items(string $text): array
    {
        // A comma inside a supplied proficiency note belongs to that skill.
        return array_values(array_filter(array_map('trim', preg_split('/[,;|](?![^()]*\))/u', $text))));
    }

    private function category(string $skill, ?string $profile): string
    {
        return match ($profile) {
            'software' => match (true) {
                (bool) preg_match('/^(?:Python|PHP|JavaScript|TypeScript|SQL|HTML5?|CSS3?|Java|C#|C\+\+|Ruby|Go|Rust|R)(?:\s*\(|$)/iu', $skill) => 'Programming',
                (bool) preg_match('/Laravel|React|Angular|Vue|Django|Flask|Spring|Scikit|TensorFlow|framework/iu', $skill) => 'Frameworks',
                (bool) preg_match('/MySQL|MongoDB|PostgreSQL|SQLite|SQL Server|Oracle Database|Redis|database/iu', $skill) => 'Databases',
                (bool) preg_match('/Agile|Scrum|Kanban|test.driven|waterfall/iu', $skill) => 'Methods',
                default => 'Tools',
            },
            'accounting' => match (true) {
                (bool) preg_match('/GAAP|IFRS|compliance|audit|statutory|tax|contract reporting/iu', $skill) => 'Compliance',
                (bool) preg_match('/team development|supervision|strategic planning|staff management/iu', $skill) => 'Leadership',
                default => 'Technical',
            },
            'engineering' => match (true) {
                (bool) preg_match('/safety|HAZOP|risk assessment/iu', $skill) => 'Safety',
                (bool) preg_match('/ISO|ASME|ASTM|standards|code compliance/iu', $skill) => 'Standards',
                (bool) preg_match('/design|drafting|modelling|modeling|simulation/iu', $skill) => 'Design',
                (bool) preg_match('/CAD|SolidWorks|MATLAB|Revit|ANSYS|AutoCAD/iu', $skill) => 'Tools',
                default => 'Methods',
            },
            'management' => match (true) {
                (bool) preg_match('/strategy|strategic|forecast|business planning/iu', $skill) => 'Strategy',
                (bool) preg_match('/governance|compliance|risk|audit/iu', $skill) => 'Governance',
                (bool) preg_match('/project|programme|program management|delivery|Agile|Scrum/iu', $skill) => 'Delivery',
                (bool) preg_match('/Excel|Power BI|Microsoft|Jira|Asana|SAP|software/iu', $skill) => 'Tools',
                default => 'Operations',
            },
            default => 'Technical',
        };
    }
}
