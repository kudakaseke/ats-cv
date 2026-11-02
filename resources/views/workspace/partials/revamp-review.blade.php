@php
    $assessment = $comparison['assessment'] ?? [];
    $clean = fn ($text) => trim(preg_replace('/[\x{F06C}\x{2022}\x{25CF}\x{25AA}\x{25E6}]/u', '', (string) $text));
    $labels = fn ($items) => collect($items)->filter(fn ($item) => is_string($item))->map($clean)->filter()->unique(fn ($item) => mb_strtolower($item))->values();
    $requirements = collect($assessment['requirements'] ?? []);
    $fullMatch = fn ($item) => in_array($item['revamped_status'] ?? $item['original_status'] ?? '', ['matched', 'demonstrated', 'directly_evidenced'], true);
    $localMatches = $requirements->isEmpty() && isset($comparison['job_description'], $comparison['revised_text'])
        ? app(\App\Services\CvKeywordMatcher::class)->compare($comparison['job_description'], $comparison['revised_text']) : [];
    $met = $labels($requirements->isNotEmpty() ? $requirements->filter($fullMatch)->pluck('requirement') : ($localMatches['matched'] ?? $comparison['added_keywords'] ?? []));
    $missing = $labels($requirements->isNotEmpty() ? $requirements->reject($fullMatch)->pluck('requirement') : ($comparison['remaining_keywords'] ?? []));
    $recommendations = $labels([...($assessment['recommendations'] ?? []), ...collect($comparison['suggestions'] ?? [])->map(fn ($item) => is_array($item) ? ($item['suggestion'] ?? '') : $item)->all()]);
    $scoreWarnings = isset($comparison['keyword_after'], $comparison['keyword_before']) && $comparison['keyword_after'] <= $comparison['keyword_before']
        ? ['Wording Match has not increased. Compare the scores above; only add skills and experience you can support.'] : [];
    $warnings = $labels([...($comparison['warnings'] ?? []), ...($assessment['cross_industry_warnings'] ?? []), ...$scoreWarnings]);
    $assessmentWarnings = $assessment['warnings'] ?? [];
    $added = $labels($comparison['added_keywords'] ?? []);
@endphp
<details open data-review-section="changes">
    <summary>What AI changed</summary>
    @if(!empty($comparison['changes']))<ul>@foreach($comparison['changes'] as $change)<li>{{ $clean($change) }}</li>@endforeach</ul>
    @else<p>No detailed change report is available for this draft.</p>@endif
    @if($added->isNotEmpty())<h3>Supported wording added</h3><ul>@foreach($added as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>@endif
</details>
<details open data-review-section="recommendations">
    <summary>Recommendations</summary>
    @if($recommendations->isNotEmpty())<ul>@foreach($recommendations as $recommendation)<li>{{ $recommendation }}</li>@endforeach</ul>
    @else<p>No additional recommendations were recorded.</p>@endif
    @if(!empty($assessment['questions_for_candidate']))<h3>Information to confirm</h3><ul>@foreach($assessment['questions_for_candidate'] as $question)<li>{{ $question }}</li>@endforeach</ul>@endif
</details>
<details open data-review-section="met">
    <summary>Job Requirements</summary>
    <p>Requirements assessed as met by the tailored CV.</p>
    @if($met->isNotEmpty())<ul>@foreach($met as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
    @else<p>No requirements were confirmed as fully met.</p>@endif
    @if(!empty($comparison['green_flags']))<h3>Candidate strengths</h3><ul>@foreach($comparison['green_flags'] as $flag)<li>{{ $flag }}</li>@endforeach</ul>@endif
</details>
<details open class="missing-requirements" data-review-section="missing">
    <summary>Requirements still missing ({{ $missing->count() }})</summary>
    @if($missing->isNotEmpty())<ul>@foreach($missing as $requirement)<li>{{ $requirement }}</li>@endforeach</ul><p>These requirements are missing, partial or unconfirmed. Add them only when supported by your actual qualifications, skills or experience.</p>
    @else<p>No missing requirements were recorded.</p>@endif
</details>
<details data-review-section="comparison">
    <summary>Compare original and tailored text</summary>
    @if(isset($comparison['original_text'], $comparison['revised_text']))<div class="checker-text-comparison"><div><h3>Original CV</h3><pre>{{ $comparison['original_text'] }}</pre></div><div><h3>Tailored CV</h3><pre>{{ $comparison['revised_text'] }}</pre></div></div>
    @else<p>The original and tailored text comparison is unavailable for this draft.</p>@endif
</details>
<details open class="checker-review-warnings" data-review-section="warnings">
    <summary>Warnings</summary>
    @if($warnings->isNotEmpty())<ul>@foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>@endif
    @if($assessmentWarnings)<ul>@foreach($assessmentWarnings as $warning)<li><strong>{{ ucfirst($warning['severity'] ?? 'Review') }}: {{ $clean($warning['requirement'] ?? '') }}</strong> — {{ $warning['explanation'] ?? '' }} {{ $warning['recommended_action'] ?? '' }}</li>@endforeach</ul>@endif
    @if($warnings->isEmpty() && !$assessmentWarnings)<p>No warnings were recorded.</p>@endif
</details>
