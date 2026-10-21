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
                    $result[mb_strtolower($item)] = $item;
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
        $data['core_strengths'] = implode(', ', self::strengths($data['core_strengths'] ?? '', $data['competencies'] ?? ''));
        $data['competencies'] = '';
        $data['references'] = self::references($data['references'] ?? '');

        return $data;
    }
}
