<?php

namespace App\Services;

use App\Models\CvAnalysis;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CvReviewComparison
{
    public const VERSION = 5;

    public function refreshForUser(array $comparison, ?int $userId): array
    {
        if (($comparison['comparison_version'] ?? 0) === self::VERSION || ! isset($comparison['original_text'], $comparison['revised_text'])) {
            return $comparison;
        }
        $job = $comparison['job_description'] ?? null;
        if (! $job && $userId) {
            // Recover an older draft's advert only from the same user's exact
            // cached rewrite and matching saved requirement set, never another account.
            $operations = DB::table('ai_operations')->where('user_id', $userId)->where('feature', 'revamp')->where('status', 'completed')->latest('id')->limit(20)->get();
            foreach ($operations as $operation) {
                try {
                    $payload = json_decode(Crypt::decryptString($operation->result), true, 64, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    continue;
                }
                if (($payload['rewritten_resume'] ?? '') !== $comparison['revised_text']) {
                    continue;
                }
                $analyses = CvAnalysis::where('user_id', $userId)->where('created_at', '<=', $operation->created_at)->latest('id')->limit(20)->get();
                foreach ($analyses as $analysis) {
                    if (($analysis->results['matched'] ?? null) === ($payload['before']['matched'] ?? false)
                        && ($analysis->results['missing'] ?? null) === ($payload['before']['missing'] ?? false)) {
                        $job = $analysis->job_description;
                        break 2;
                    }
                }
            }
        }

        return $job ? $this->make($job, $comparison['original_text'], $comparison['revised_text'], $comparison['changes'] ?? []) : $comparison;
    }

    public function make(string $job, string $original, string $revised, array $changes = []): array
    {
        $matcher = new CvKeywordMatcher;
        $before = $matcher->compare($job, $original);
        $after = $matcher->compare($job, $revised);
        $groups = [];
        foreach ($after['requirement_groups'] ?? [] as $category => $items) {
            $groups[$category] = array_values(array_column(array_filter($items, fn ($item) => ! $item['matched']), 'requirement'));
        }
        $groups = array_replace(array_fill_keys(['qualifications', 'skills', 'competencies'], []), $groups);
        $experience = $after['experience_check'];
        $warnings = [];
        if ($experience['required_years'] !== null && ($experience['shortfall'] || $experience['cv_years'] === null)) {
            $warnings[] = $experience['message'];
            if (! empty($experience['unreadable_dates'])) {
                $warnings[] = 'Correct these employment dates to compare experience accurately: '.implode('; ', $experience['unreadable_dates']);
            }
        }

        return ['comparison_version' => self::VERSION, 'job_description' => $job, 'method' => $after['method'],
            'before' => $before['similarity_percent'], 'after' => $after['similarity_percent'],
            'keyword_before' => $before['keyword_score'], 'keyword_after' => $after['keyword_score'],
            'green_flags' => $after['green_flags'] ?? [], 'certificate_bonus' => $after['certificate_bonus'] ?? 0,
            'added_keywords' => array_values(array_diff($after['matched'], $before['matched'])),
            'remaining_keywords' => $after['missing'], 'remaining_groups' => $groups, 'warnings' => $warnings,
            'experience_check' => $experience, 'suggestions' => $after['suggestions'], 'changes' => $changes,
            'original_text' => $original, 'revised_text' => $revised];
    }
}
