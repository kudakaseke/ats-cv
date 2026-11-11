@props(['assessment', 'revised' => false, 'comparison' => [], 'comparisonWarnings' => [], 'deferWarnings' => false])
@php
    $requirements = $assessment['requirements'] ?? [];
    $statusKey = $revised ? 'revamped_status' : 'original_status';
    $categoryOrder = ['education' => 0, 'certifications' => 1, 'skills' => 2, 'competencies' => 3, 'experience' => 4, 'responsibilities' => 5];
    usort($requirements, fn ($a, $b) => (($a['importance'] ?? 'preferred') === 'mandatory' ? 0 : 1) <=> (($b['importance'] ?? 'preferred') === 'mandatory' ? 0 : 1)
        ?: (($categoryOrder[$a['category'] ?? ''] ?? 99) <=> ($categoryOrder[$b['category'] ?? ''] ?? 99))
        ?: strcasecmp($a['requirement'] ?? '', $b['requirement'] ?? ''));
    $metStatuses = ['matched', 'demonstrated', 'directly_evidenced'];
    $missingRequirements = array_values(array_filter($requirements, fn ($item) => !in_array($item[$statusKey] ?? '', $metStatuses, true)));
    $missingLabels = array_values(array_unique(array_filter([
        ...array_map(fn ($item) => $item['requirement'] ?? '', $missingRequirements),
    ])));
    $recommendations = [];
    foreach ($missingRequirements as $item) {
        $requirement = $item['requirement'] ?? 'this job requirement';
        if (in_array($item['category'] ?? '', ['education', 'certifications'], true)) {
            $recommendations[] = 'The CV does not evidence “'.$requirement.'”. If you hold it, add its exact name and completion or current status; otherwise, do not claim it.';
        } elseif (($item['category'] ?? '') === 'experience') {
            $recommendations[] = 'The CV does not fully evidence “'.$requirement.'”. If you have relevant experience, provide the role, dates and duties so it can be assessed accurately.';
        } else {
            $recommendations[] = 'The CV does not fully evidence “'.$requirement.'”. Add it only if you can support it with a specific example or confirmed fact.';
        }
    }
    $recommendations = array_values(array_unique(array_filter([
        ...$recommendations,
        ...($assessment['questions_for_candidate'] ?? []),
        ...($assessment['information_requiring_confirmation'] ?? []),
        ...($assessment['recommendations'] ?? []),
    ])));
    $missingLabels = array_values(array_unique([...$missingLabels, ...($comparison['remaining_keywords'] ?? []), ...($assessment['missing_core_requirements'] ?? [])]));
    $experience = $assessment['experience'] ?? [];
    $yearsRequired = $experience['required_years'] ?? null;
    $yearsRelevant = $experience['documented_relevant_years'] ?? ($assessment['relevant_experience']['direct_years'] ?? null);
    $yearsTotal = $experience['total_professional_years'] ?? null;
@endphp
<section class="ai-assessment-results" aria-label="Job assessment details">
    <details open class="assessment-recommendations">
        <summary id="assessment-recommendations-title">Recommendation</summary>
        @if($recommendations)
            <ol>@foreach($recommendations as $recommendation)<li>{{ $recommendation }}</li>@endforeach</ol>
        @else
            <p>No additional changes are recommended from the evidence provided.</p>
        @endif
    </details>

    <details open class="assessment-requirements">
        <summary id="assessment-requirements-title">Job requirements</summary>
        @if($requirements)
            <div class="assessment-table-wrap" role="region" aria-label="Job requirements table" tabindex="0">
                <table class="assessment-table">
                    <thead><tr><th scope="col">Priority</th><th scope="col">Job requirement</th><th scope="col">CV / Resume</th><th scope="col">Comments and evidence</th></tr></thead>
                    <tbody>
                        @foreach($requirements as $item)
                            @php
                                $status = $item[$statusKey] ?? 'uncertain';
                                $statusLabel = match ($status) {
                                    'matched', 'demonstrated', 'directly_evidenced' => 'Matched',
                                    'partially_matched', 'partially_evidenced', 'transferable_experience' => 'Partially matched',
                                    'stated_only' => 'Stated only',
                                    'not_evidenced' => 'Not evidenced',
                                    default => 'Uncertain',
                                };
                                $statusTone = in_array($status, $metStatuses, true) ? 'matched' : (in_array($status, ['partially_matched', 'partially_evidenced', 'transferable_experience'], true) ? 'partial' : 'missing');
                            @endphp
                            <tr>
                                <td><span class="assessment-priority {{ ($item['importance'] ?? '') === 'mandatory' ? 'mandatory' : 'preferred' }}">{{ ucfirst($item['importance'] ?? 'preferred') }}</span></td>
                                <th scope="row"><span class="assessment-category">{{ ucfirst($item['category'] ?? 'requirement') }}</span>{{ $item['requirement'] ?? '' }}</th>
                                <td><span class="assessment-status {{ $statusTone }}">{{ $statusLabel }}</span></td>
                                <td>{{ $item['evidence'] ?: 'Not evidenced in the supplied CV.' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p>No job requirements were returned for this assessment.</p>
        @endif
    </details>

    <details open class="assessment-missing">
        <summary id="assessment-missing-title">Missing requirements ({{ count($missingLabels) }})</summary>
        @if($missingLabels)
            <ul>@foreach($missingLabels as $missing)<li>{{ $missing }}</li>@endforeach</ul>
            <p>Add a missing item only if you can verify it from your experience or records.</p>
        @else
            <p>Every assessed requirement has evidence in the CV.</p>
        @endif
    </details>

    @if(isset($slot) && trim((string) $slot) !== ''){{ $slot }}@endif
    @unless($deferWarnings)<x-ai-assessment-warnings :assessment="$assessment" :revised="$revised" :comparison-warnings="$comparisonWarnings" />@endunless
</section>
