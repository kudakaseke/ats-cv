<?php

namespace App\Services;

class CvSupportedTerms
{
    public function apply(array $resume, array $assessment, string $source): array
    {
        $source = $this->words($source);
        $priority = ['skills' => [], 'core_strengths' => []];
        foreach ($assessment['requirements'] ?? [] as $item) {
            $field = match ($item['category']) {
                'skills' => 'skills', 'competencies' => 'core_strengths', default => null,
            };
            if (! $field || ! in_array($item['original_status'], ['matched', 'demonstrated', 'directly_evidenced'], true)
                || ! trim($item['evidence'] ?? '') || mb_strlen($item['requirement']) > 200) {
                continue;
            }
            // Require the quoted source evidence, not just a requirement in the advert.
            $evidence = preg_split('/[;\r\n]+/u', $item['evidence']);
            if (array_filter($evidence, fn ($quote) => $this->words($quote) !== '' && ! str_contains($source, $this->words($quote)))) {
                continue;
            }
            $label = trim(preg_replace('/^(?:Excellent|Strong|Sound)\s+/iu', '', $item['requirement']));
            if ($label !== '' && ! preg_match('/[\r\n]/', $label)) {
                $priority[$field][] = $label;
            }
        }
        foreach ($priority as $field => $items) {
            $merged = [];
            foreach ([...$items, ...($resume[$field] ?? [])] as $item) {
                $key = $this->words($item);
                if ($key !== '' && ! isset($merged[$key])) {
                    $merged[$key] = $item;
                }
            }
            $resume[$field] = array_slice(array_values($merged), 0, 100);
        }
        return $resume;
    }

    private function words(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}+#]+/u', ' ', mb_strtolower($text)));
    }
}
