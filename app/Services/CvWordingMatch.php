<?php

namespace App\Services;

class CvWordingMatch
{
    public const VERSION = 'verified-requirement-wording-v1';

    public function score(array $assessment, string $source, string $text, array $confirmed = []): array
    {
        $matched = $missing = [];
        $earned = $possible = 0;
        $matcher = app(CvKeywordMatcher::class);
        foreach ($assessment['requirements'] ?? [] as $item) {
            $weight = $item['importance'] === 'mandatory' ? 3 : 1;
            $possible += $weight;
            $supported = in_array($item['original_status'], ['matched', 'demonstrated', 'directly_evidenced'], true)
                && app(CvAiV2Report::class)->evidenceExists($item['evidence'] ?? '', $source);
            $supported = $supported || ($item['category'] === 'skills' && in_array(mb_strtolower($item['requirement']), array_map('mb_strtolower', $confirmed), true));
            $represented = false;
            if ($supported) {
                $needed = array_unique($matcher->terms($item['requirement']));
                foreach (preg_split('/\R/u', $text) as $line) {
                    if (preg_match('/\b(?:no|not|without|lack|lacking|missing)\b/i', $line)) continue;
                    if ($needed && ! array_diff($needed, $matcher->terms($line))) { $represented = true; break; }
                }
            }
            if ($represented) { $earned += $weight; $matched[] = $item['requirement']; }
            else $missing[] = $item['requirement'];
        }
        $score = $possible ? min($missing ? 99 : 100, (int) round(100 * $earned / $possible)) : 0;
        return ['keyword_score' => $score, 'base_requirement_score' => $score,
            'total' => count($matched) + count($missing), 'matched_weight' => $earned, 'total_weight' => $possible,
            'matched' => $matched, 'missing' => $missing, 'wording_method' => self::VERSION];
    }
}
