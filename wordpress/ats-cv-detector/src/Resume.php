<?php

namespace ATSCV;

final class Resume
{
    public const FREE_TEMPLATES = ['double-column', 'elegant', 'graduate', 'essential', 'crest', 'student', 'portfolio'];

    public const FIELDS = ['name', 'role', 'email', 'phone', 'location', 'website', 'summary', 'skills', 'core_strengths', 'references', 'raw_text'];

    public const ROWS = ['experience' => ['title', 'company', 'period', 'location', 'description'], 'education' => ['degree', 'school', 'period', 'grade'],
        'courses' => ['title', 'institution', 'period'], 'achievements' => ['title', 'description'], 'languages' => ['name', 'level'], 'custom_sections' => ['title', 'content']];

    public static function templates(): array
    {
        static $templates;

        return $templates ??= require ATSCV_DIR.'config/templates.php';
    }

    public static function allowed(int $user, string $template, ?object $existing = null): bool
    {
        $known = array_column(self::templates(), 'id');
        if (! in_array($template, $known, true)) {
            return false;
        }

        return Credits::current($user) !== null || in_array($template, self::FREE_TEMPLATES, true)
            || ($existing && $existing->template === $template);
    }

    public static function normalize(array $data): array
    {
        if (strlen(wp_json_encode($data)) > 180000) {
            throw new \InvalidArgumentException('This CV is too long.');
        }
        $clean = [];
        foreach (self::FIELDS as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) {
                throw new \InvalidArgumentException('Invalid CV field.');
            }
            $clean[$field] = trim(wp_strip_all_tags($data[$field] ?? ''));
            if (mb_strlen($clean[$field]) > ($field === 'raw_text' ? 60000 : (in_array($field, ['summary', 'skills', 'core_strengths', 'references'], true) ? 5000 : 255))) {
                throw new \InvalidArgumentException('A CV field exceeds its length limit.');
            }
        }
        foreach (self::ROWS as $section => $fields) {
            if (! is_array($data[$section] ?? [])) {
                throw new \InvalidArgumentException('Invalid CV section.');
            }
            if (count($data[$section] ?? []) > 30) {
                throw new \InvalidArgumentException('Too many CV entries.');
            }
            $clean[$section] = [];
            foreach ($data[$section] ?? [] as $row) {
                if (! is_array($row)) {
                    throw new \InvalidArgumentException('Invalid CV entry.');
                }
                $entry = [];
                foreach ($fields as $field) {
                    if (isset($row[$field]) && ! is_string($row[$field])) {
                        throw new \InvalidArgumentException('Invalid CV entry.');
                    }
                    $entry[$field] = trim(wp_strip_all_tags($row[$field] ?? ''));
                    if (mb_strlen($entry[$field]) > (in_array($field, ['description', 'content'], true) ? 5000 : 255)) {
                        throw new \InvalidArgumentException('A CV entry is too long.');
                    }
                }
                if (array_filter($entry)) {
                    $clean[$section][] = $entry;
                }
            }
        }
        $clean['section_order'] = array_values(array_unique(array_filter($data['section_order'] ?? [], static fn ($v) => is_string($v) && in_array($v, ['summary', 'skills', 'core_strengths', 'experience', 'education', 'courses', 'achievements', 'languages', 'custom_sections', 'references'], true))));
        $clean['labels'] = [];
        foreach (($data['labels'] ?? []) as $section => $label) {
            if (in_array($section, ['summary', 'skills', 'core_strengths', 'experience', 'education', 'courses', 'achievements', 'languages', 'references'], true) && is_string($label)) {
                $clean['labels'][$section] = mb_substr(wp_strip_all_tags($label), 0, 80);
            }
        }

        return $clean;
    }

    public static function text(array $cv): string
    {
        if (! empty($cv['raw_text'])) {
            return $cv['raw_text'];
        }
        $parts = [];
        foreach (['name', 'role', 'email', 'phone', 'location', 'website', 'summary', 'skills', 'core_strengths'] as $field) {
            if (! empty($cv[$field])) {
                $parts[] = ucfirst(str_replace('_', ' ', $field))."\n".$cv[$field];
            }
        }
        foreach (self::ROWS as $section => $fields) {
            foreach ($cv[$section] ?? [] as $row) {
                $parts[] = ucfirst(str_replace('_', ' ', $section))."\n".implode("\n", array_filter(array_intersect_key($row, array_flip($fields))));
            }
        }
        if (! empty($cv['references'])) {
            $parts[] = 'References'."\n".$cv['references'];
        }

        return implode("\n\n", $parts);
    }

    public static function find(int $id, int $owner): ?object
    {
        global $wpdb;
        $table = DB::table('resumes');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND user_id=%d", $id, $owner));
    }

    public static function save(int $user, int $id, int $revision, string $title, string $template, array $data): object
    {
        $data = self::normalize($data);

        return DB::transaction(static function () use ($user, $id, $revision, $title, $template, $data) {
            global $wpdb;
            DB::lock($user);
            $existing = $id ? self::find($id, $user) : null;
            if ($id && ! $existing) {
                throw new \DomainException('CV not found.');
            }
            if ($existing && (int) $existing->revision !== $revision) {
                throw new \DomainException('This CV changed in another tab. Reopen it before saving.');
            }
            if (! self::allowed($user, $template, $existing)) {
                throw new \DomainException('Choose a free template or an active paid plan.');
            }
            $table = DB::table('resumes');
            if (! $existing && ! Credits::current($user) && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id=%d", $user)) >= 1) {
                throw new \DomainException('Free accounts can save one CV. Edit the saved CV or choose a paid plan.');
            }
            $values = ['title' => mb_substr(sanitize_text_field($title), 0, 150) ?: 'My CV', 'template' => $template,
                'content' => Config::crypt(wp_json_encode($data)), 'revision' => $existing ? $existing->revision + 1 : 1, 'updated_at' => DB::now()];
            if ($existing) {
                DB::update('resumes', $values, ['id' => $id]);
            } else {
                $id = DB::insert('resumes', $values + ['user_id' => $user, 'created_at' => DB::now()]);
            }

            return self::find($id, $user);
        });
    }

    public static function import(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Choose a readable PDF or DOCX under 5 MB.');
        }
        $path = $file['tmp_name'];
        if (! is_uploaded_file($path)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $text = Documents::extract($path, $extension);
        if (mb_strlen($text) < 80) {
            throw new \DomainException('Not enough readable text. Image-only, encrypted or scanned files need OCR before importing.');
        }
        $data = self::normalize(['raw_text' => mb_substr($text, 0, 60000)]);

        return ['data' => $data, 'message' => 'Review the imported text before analysis or export. Import preserves readable text, not the original layout. The uploaded file is not retained.'];
    }
}
