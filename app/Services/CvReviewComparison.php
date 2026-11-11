<?php

namespace App\Services;

use App\Models\CvAnalysis;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CvReviewComparison
{
    public const VERSION = 12;

    public function refreshForUser(array $comparison, ?int $userId, ?array $generated = null): array
    {
        if (isset($generated['assessment'], $generated['text'], $generated['original_text'], $generated['job_description'], $comparison['original_text'], $comparison['revised_text'], $comparison['job_description'])
            && CvDocumentIdentity::same($generated['text'], $comparison['revised_text'])
            && CvDocumentIdentity::same($generated['original_text'], $comparison['original_text'])
            && CvDocumentIdentity::same($generated['job_description'], $comparison['job_description'])) {
            $comparison['assessment'] = $generated['assessment'];
        }
        if (($comparison['comparison_version'] ?? 0) === self::VERSION || ! isset($comparison['original_text'], $comparison['revised_text'])) {
            return $this->withExperienceAssessment($comparison);
        }
        $job = $comparison['job_description'] ?? null;
        if (! $job && $userId) {
            // Recover an older draft's advert only from the same user's exact
            // cached rewrite and matching saved requirement set, never another account.
            $operations = DB::table('ai_operations')->where('user_id', $userId)->where('feature', 'revamp')->where('status', 'completed')->latest('id')->limit(20)->get();
            $matching = [];
            foreach ($operations as $operation) {
                try {
                    $payload = json_decode(Crypt::decryptString($operation->result), true, 64, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    continue;
                }
                if (($payload['rewritten_resume'] ?? '') !== $comparison['revised_text']) {
                    continue;
                }
                $matching[$operation->id] = $payload;
            }
            // One joined query replaces a history query for each matching rewrite.
            // Rank per operation so each keeps precisely its former latest-20 boundary.
            if ($matching) {
                $ranked = DB::table('ai_operations as operations')->join('cv_analyses as checks', function ($join) {
                    $join->on('checks.user_id', '=', 'operations.user_id')->on('checks.created_at', '<=', 'operations.created_at');
                })->where('operations.user_id', $userId)->whereIn('operations.id', array_keys($matching))
                    ->select('checks.*', 'operations.id as operation_id')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY operations.id ORDER BY checks.id DESC) as match_rank');
                $groups = CvAnalysis::query()->fromSub($ranked, 'ranked_checks')->where('match_rank', '<=', 20)
                    ->orderBy('operation_id')->orderBy('match_rank')->get()->groupBy('operation_id');
            }
            foreach ($matching as $operationId => $payload) {
                foreach ($groups->get($operationId, collect()) as $analysis) {
                    if (($analysis->results['matched'] ?? null) === ($payload['before']['matched'] ?? false)
                        && ($analysis->results['missing'] ?? null) === ($payload['before']['missing'] ?? false)) {
                        $job = $analysis->job_description;
                        break 2;
                    }
                }
            }
        }

        if (! $job) {
            return $comparison;
        }
        $refreshed = $this->make($job, $comparison['original_text'], $comparison['revised_text'], $comparison['changes'] ?? []);
        if (isset($comparison['assessment'])) {
            $refreshed['assessment'] = $comparison['assessment'];
        }
        return $this->withExperienceAssessment($refreshed);
    }

    private function withExperienceAssessment(array $comparison): array
    {
        if (isset($comparison['experience_check'], $comparison['assessment'])) {
            $comparison['experience_check'] = app(CvExperienceMatcher::class)->reconcile($comparison['experience_check'], $comparison['assessment']);
            $comparison['warnings'] = [];
            $check = $comparison['experience_check'];
            if ($check['required_years'] !== null && ($check['shortfall'] || $check['cv_years'] === null || ($check['source'] ?? '') === 'ai_evidence_assessment' && $check['relevant_years'] === null)) {
                $comparison['warnings'][] = $check['message'];
            }
            foreach ($check['assessment_date_warnings'] ?? [] as $warning) $comparison['warnings'][] = $warning;
        }
        return app(CvCompatibilityScore::class)->review($comparison);
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
            'structure_before' => app(CvCompatibilityScore::class)->structureScore($original),
            'structure_after' => app(CvCompatibilityScore::class)->structureScore($revised),
            'green_flags' => $after['green_flags'] ?? [], 'certificate_bonus' => $after['certificate_bonus'] ?? 0,
            'added_keywords' => array_values(array_diff($after['matched'], $before['matched'])),
            'remaining_keywords' => $after['missing'], 'remaining_groups' => $groups, 'warnings' => $warnings,
            'experience_check' => $experience, 'suggestions' => $after['suggestions'], 'changes' => $changes,
            'original_text' => $original, 'revised_text' => $revised];
    }
}
