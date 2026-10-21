<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CvStructuredResume
{
    private const CONTACT = ['name', 'role', 'email', 'phone', 'location', 'website', 'summary', 'references'];

    private const LISTS = ['skills', 'core_strengths', 'competencies'];

    private const ROWS = [
        'experience' => ['title', 'company', 'period', 'location', 'responsibilities'],
        'education' => ['degree', 'school', 'period', 'location', 'grade'],
        'achievements' => ['title', 'description'], 'courses' => ['title', 'institution', 'period'],
        'languages' => ['name', 'level'], 'custom_sections' => ['title', 'content'],
    ];

    public function schema(): array
    {
        $properties = [];
        foreach (self::CONTACT as $key) {
            $properties[$key] = ['type' => 'STRING'];
        }
        foreach (self::LISTS as $key) {
            $properties[$key] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        }
        foreach (self::ROWS as $key => $fields) {
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = $field === 'responsibilities' ? ['type' => 'ARRAY', 'items' => ['type' => 'STRING']] : ['type' => 'STRING'];
            }
            if ($key === 'languages') {
                $row['level']['enum'] = ['Native', 'Fluent', 'Advanced', 'Intermediate', 'Beginner'];
                $row['level']['nullable'] = true;
            }
            $properties[$key] = ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => $row, 'required' => $fields]];
        }
        $properties['role']['description'] = 'Existing applicant professional headline from the original CV, never the advertised vacancy title. Empty when absent. Never rename the applicant profession to match an advert.';
        $properties['experience']['description'] = 'One object per job. Preserve the original historical job title exactly. Title is only the job position; company is only the employer. Responsibilities belong only to that specific job.';
        $properties['education']['description'] = 'One object per qualification. Degree is the qualification; school is the institution. Dates belong in period. Grade is the supplied degree class, GPA or grade; keep it separate and never invent it. Empty if not supplied.';
        $properties['custom_sections']['description'] = 'For projects or other titled entries, content must put each entry title on its own line, then its description on following lines. Separate entries with a blank line. Do not combine a title and description into one sentence or use Markdown formatting.';
        $properties['achievements']['description'] = 'Keep the short achievement title separate from its description. The renderer displays a bold title with description underneath.';
        $properties['skills']['description'] = 'Short skill names only, not employment duties, education or full paragraphs.';
        $properties['core_strengths']['description'] = 'One combined strengths and competencies list: concise, distinct qualities relevant to the job and supported by the original CV. The application displays five items across each row. Prefer ten relevant supported competencies for two rows; never invent items to fill the grid.';
        $properties['competencies']['description'] = 'Legacy compatibility field. Return an empty array; put all strengths and competencies together in core_strengths.';
        $properties['references']['description'] = 'Preserve provided referees only, one referee per line: Name and surname | Position | Phone number | Email. Leave unavailable values blank in their columns; never substitute an organisation for a missing position, never invent contacts, and never put referees in applicant contact fields. Empty if none supplied.';

        return ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties)];
    }

    public function data(array $resume): array
    {
        $rules = [];
        $limits = ['name' => 150, 'role' => 150, 'email' => 255, 'phone' => 80, 'location' => 150, 'website' => 255, 'summary' => 5000, 'references' => 5000];
        foreach ($limits as $key => $max) {
            $rules[$key] = [$key === 'name' ? 'required' : 'present', 'nullable', 'string', 'max:'.$max];
        }
        foreach (self::LISTS as $key) {
            $rules[$key] = ['present', 'array', 'max:100'];
            $rules[$key.'.*'] = ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'];
        }
        foreach (self::ROWS as $key => $fields) {
            $rules[$key] = ['present', 'array', 'max:20'];
            $rules[$key.'.*'] = ['array:'.implode(',', $fields)];
            foreach ($fields as $field) {
                $path = $key.'.*.'.$field;
                if ($field === 'responsibilities') {
                    $rules[$path] = ['present', 'array', 'max:50'];
                    $rules[$path.'.*'] = ['required', 'string', 'max:1000'];
                } else {
                    $max = $field === 'content' ? 5000 : ($field === 'description' ? 2000 : (in_array($field, ['period', 'location']) ? 150 : ($key === 'languages' ? 100 : 200)));
                    $rules[$path] = [($key === 'courses' && $field === 'period') || ($key === 'education' && $field === 'grade') ? 'sometimes' : 'present', 'nullable', 'string', 'max:'.$max];
                    if (! in_array($field, ['content', 'description'])) {
                        $rules[$path][] = 'not_regex:/[\r\n]/';
                    }
                    if ($field === 'level') {
                        $rules[$path][] = Rule::in(['', 'Native', 'Fluent', 'Advanced', 'Intermediate', 'Beginner']);
                    }
                }
            }
        }
        $validated = Validator::make($resume, $rules)->validate();
        $data = [];
        foreach (self::CONTACT as $key) {
            $data[$key] = trim($validated[$key] ?? '');
        }
        foreach (self::LISTS as $key) {
            $items = array_map('trim', $validated[$key]);
            $grouped = $key === 'skills' && array_filter($items, fn ($item) => preg_match('/^[^:]+:/u', $item));
            $data[$key] = implode($grouped ? "\n" : ', ', $items);
        }
        foreach (self::ROWS as $key => $fields) {
            $data[$key] = [];
            foreach ($validated[$key] as $row) {
                $entry = [];
                foreach ($fields as $field) {
                    if ($field === 'responsibilities') {
                        $entry['description'] = implode("\n", array_map(fn ($line) => preg_replace('/^\s*[-\x{2022}*]\s*/u', '', trim($line)), $row[$field]));
                    } else {
                        $entry[$field] = trim($row[$field] ?? '');
                    }
                }
                $data[$key][] = $entry;
            }
        }
        Validator::make($data, ['skills' => ['max:2000'], 'core_strengths' => ['max:5000'], 'competencies' => ['max:5000'], 'experience.*.description' => ['max:5000']])->validate();

        return CvResumeFormat::builder($data);
    }

    public function text(array $data): string
    {
        $data = CvResumeFormat::builder($data);
        $lines = [];
        foreach (['name', 'role', 'email', 'phone', 'location', 'website'] as $key) {
            if (! empty($data[$key])) {
                $lines[] = $data[$key];
            }
        }
        $section = function (string $heading, array $body) use (&$lines) {
            if ($body) {
                $lines = [...$lines, '', $heading, ...$body];
            }
        };
        foreach (['summary' => 'Professional Summary'] as $key => $heading) {
            if (! empty($data[$key])) {
                $section($heading, [$data[$key]]);
            }
        }
        foreach (['experience' => ['Professional Experience', ['title' => 'Position', 'company' => 'Company', 'period' => 'Period', 'location' => 'Location']], 'education' => ['Education', ['degree' => 'Degree', 'school' => 'School', 'period' => 'Period', 'location' => 'Location', 'grade' => 'Degree class/grade']]] as $key => [$heading, $fields]) {
            $body = [];
            foreach ($data[$key] as $row) {
                if ($body) {
                    $body[] = '';
                }
                foreach ($fields as $field => $label) {
                    if (($row[$field] ?? '') !== '') {
                        $body[] = $label.': '.$row[$field];
                    }
                }
                if (! empty($row['description'])) {
                    foreach (explode("\n", $row['description']) as $line) {
                        $body[] = '- '.$line;
                    }
                }
            }
            $section($heading, $body);
        }
        $section('Certification', array_map(fn ($row) => implode(' - ', array_filter($row, fn ($value) => $value !== '')), $data['courses']));
        if ($data['skills'] !== '') {
            $section('Technical Skills', [$data['skills']]);
        }
        foreach (['achievements' => 'Achievements', 'languages' => 'Languages'] as $key => $heading) {
            $section($heading, array_map(fn ($row) => implode(' - ', array_filter($row, fn ($value) => $value !== '')), $data[$key]));
        }
        foreach ($data['custom_sections'] as $row) {
            if ($row['content'] !== '') {
                $section($row['title'], [$row['content']]);
            }
        }
        if ($data['core_strengths'] !== '') {
            $section('Competencies', [$data['core_strengths']]);
        }
        if ($data['references'] !== '') {
            $section('References', [$data['references']]);
        }

        $text = trim(implode("\n", $lines));
        Validator::make(['text' => $text], ['text' => ['required', 'string', 'max:20000']])->validate();

        return $text;
    }
}
