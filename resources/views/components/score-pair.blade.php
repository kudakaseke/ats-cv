@props(['dynamic' => false, 'wording' => null, 'compatibility' => null, 'wordingBefore' => null, 'compatibilityBefore' => null])
<div class="checker-score-pair" aria-label="CV match scores">
    @foreach([
        ['label' => 'Wording Match', 'score' => $wording, 'before' => $wordingBefore, 'expression' => 'result?.keyword_score', 'tone' => 'wording', 'description' => 'Priority-weighted job requirements recognised in your CV. Mandatory items count more than preferred items. Equivalent wording may be missed.'],
        ['label' => 'ATS Compatibility', 'score' => $compatibility, 'before' => $compatibilityBefore, 'expression' => 'result?.compatibility_score', 'tone' => 'compatibility', 'description' => 'Combines job-relevant evidence, Wording Match, and basic readable CV structure such as section headings and contact details. It does not assess visual page layout. An app estimate, not an official employer ATS score.'],
    ] as $metric)
        <section class="checker-score-card {{ $metric['tone'] }}" aria-label="{{ $metric['label'] }}">
            <h3>{{ $metric['label'] }}</h3>
            @if($dynamic)
                <div class="checker-score-ring" :style="{ '--score': Math.max(0, Math.min(100, ({{ $metric['expression'] }}) ?? 0)) }">
                    <div><strong x-text="({{ $metric['expression'] }}) ?? '—'"></strong><span x-show="({{ $metric['expression'] }}) != null">/100</span></div>
                </div>
                <p class="checker-score-state" x-show="({{ $metric['expression'] }}) == null">Not available for this check</p>
            @else
                <div class="checker-score-ring" style="--score: {{ max(0, min(100, $metric['score'] ?? 0)) }}"><div><strong>{{ $metric['score'] ?? '—' }}</strong>@if($metric['score'] !== null)<span>/100</span>@endif</div></div>
                @if($metric['score'] === null)<p class="checker-score-state">Not available for this check</p>
                @elseif($metric['before'] !== null)<p class="checker-score-change"><span>Original {{ $metric['before'] }}/100</span> &rarr; <span>Revamped {{ $metric['score'] }}/100</span></p>@endif
            @endif
            <p class="checker-score-definition">{{ $metric['description'] }}</p>
        </section>
    @endforeach
</div>
