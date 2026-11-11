@props(['assessment', 'revised' => false, 'comparisonWarnings' => []])
@php
    $requirements = $assessment['requirements'] ?? [];
    $statusKey = $revised ? 'revamped_status' : 'original_status';
    $metStatuses = ['matched', 'demonstrated', 'directly_evidenced'];
    $alignment = strtolower(str_replace('_', ' ', $assessment['professional_alignment'] ?? ''));
    $risk = $assessment['false_positive_risk'] ?? null;
    $experience = $assessment['experience'] ?? [];
    $requiredYears = $experience['required_years'] ?? null;
    $relevantYears = $experience['documented_relevant_years'] ?? ($assessment['relevant_experience']['direct_years'] ?? null);
    $totalYears = $experience['total_professional_years'] ?? null;
    $credentialGaps = array_values(array_filter($requirements, fn ($item) => ($item['importance'] ?? '') === 'mandatory'
        && in_array($item['category'] ?? '', ['education', 'certifications'], true)
        && !in_array($item[$statusKey] ?? '', $metStatuses, true)));
    $warnings = $assessment['warnings'] ?? [];
    $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    usort($warnings, fn ($a, $b) => ($severityOrder[$a['severity'] ?? 'low'] ?? 4) <=> ($severityOrder[$b['severity'] ?? 'low'] ?? 4));
@endphp
<details open class="assessment-warning-details">
    <summary id="assessment-warnings-title">Warnings</summary>
    <section class="checker-review-warnings assessment-warnings" role="note" aria-labelledby="assessment-warnings-title">
    <ol>
        <li><strong>Job match:</strong>
            @if($alignment !== ''){{ ucfirst($alignment) }}@else Alignment could not be determined reliably.@endif
            @if(!empty($assessment['candidate_background']) || !empty($assessment['target_profession']))
                — CV background: {{ $assessment['candidate_background'] ?: 'not established' }}; target role: {{ $assessment['target_profession'] ?: ($assessment['job_information']['title'] ?? 'the advertised role') }}.
            @endif
            @if($risk) False-positive risk: {{ $risk }}.@endif
        </li>
        <li><strong>Experience:</strong>
            @if($requiredYears !== null)The job states a minimum of {{ rtrim(rtrim(number_format((float) $requiredYears, 2, '.', ''), '0'), '.') }} years.@else The job description does not state a minimum number of years.@endif
            @if($relevantYears !== null) The CV shows approximately {{ rtrim(rtrim(number_format((float) $relevantYears, 2, '.', ''), '0'), '.') }} relevant years.@else Relevant years could not be reliably confirmed from the CV.@endif
            @if($totalYears !== null) Total documented employment is approximately {{ rtrim(rtrim(number_format((float) $totalYears, 2, '.', ''), '0'), '.') }} years.@endif
            @if(!empty($experience['explanation'])) {{ $experience['explanation'] }}@endif
        </li>
        @foreach($experience['date_warnings'] ?? [] as $dateWarning)
            <li><strong>Experience dates:</strong> {{ $dateWarning }}</li>
        @endforeach
        @foreach($credentialGaps as $item)
            <li><strong>Mandatory {{ $item['category'] === 'education' ? 'qualification' : 'certificate or credential' }} not evidenced:</strong> {{ $item['requirement'] }}. The CV does not establish that it is held; confirm it and add exact details only if accurate.</li>
        @endforeach
        @foreach($warnings as $warning)
            <li><strong>{{ ucfirst($warning['severity'] ?? 'warning') }} — {{ $warning['requirement'] ?: ucfirst($warning['category'] ?? 'requirement') }}:</strong> {{ $warning['explanation'] }} @if(!empty($warning['evidence'])) Evidence: {{ $warning['evidence'] }}. @endif{{ $warning['recommended_action'] }}</li>
        @endforeach
        @foreach($assessment['cross_industry_warnings'] ?? [] as $warning)
            <li>{{ $warning }}</li>
        @endforeach
        @foreach($comparisonWarnings as $warning)
            <li>{{ $warning }}</li>
        @endforeach
        @if(!$warnings && !$credentialGaps && !$comparisonWarnings && empty($assessment['cross_industry_warnings']))
            <li>No additional eligibility or date warnings were identified.</li>
        @endif
    </ol>
    </section>
</details>
