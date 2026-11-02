<x-app-layout>
    <div class="dashboard-heading"><div><h1>CV comparison</h1><p>{{ $analysis->created_at->format('d M Y, H:i') }} · Saved result</p></div><a class="btn btn-outline" href="{{ route('workspace.module', 'history') }}">Back to saved checks</a></div>
    <section class="ats-checker">
        @if(isset($analysis->results['legacy_wording_score']))<p class="checker-method">This saved check used the previous wording method ({{ $analysis->results['legacy_wording_score'] }}/100). Its findings remain below. Run a new scan to calculate priority-weighted Wording Match and ATS Compatibility.</p>@endif
        @if(isset($analysis->results['assessment']))<details><summary>Job requirements and recommendations</summary><x-ai-assessment :assessment="$analysis->results['assessment']" /></details>@endif
        @if(!empty($analysis->results['green_flags']))<section class="checker-green-flags"><h3>Qualification strengths</h3><ul>@foreach($analysis->results['green_flags'] as $flag)<li>{{ $flag }}</li>@endforeach</ul>@if($analysis->results['certificate_bonus'] ?? 0)<p>Includes {{ $analysis->results['certificate_bonus'] }} additional certificate bonus points.</p>@endif</section>@endif
        <x-score-pair :wording="$analysis->results['keyword_score'] ?? null" :compatibility="$analysis->results['compatibility_score'] ?? null" />
        @if(isset($analysis->results['experience_check']))<section class="{{ !empty($analysis->results['experience_check']['positive']) ? 'checker-green-flags' : (!empty($analysis->results['experience_check']['shortfall']) ? 'checker-experience-warning' : 'checker-panel') }}"><h3>{{ $analysis->results['experience_check']['label'] ?? 'Experience to confirm' }}</h3><p>{{ $analysis->results['experience_check']['message'] }}</p><small>{{ $analysis->results['experience_check']['note'] }}</small></section>@endif
        @if(isset($analysis->results['ai_analysis']))<section class="checker-panel mt-6"><h2>AI recommendations</h2><p>{{ $analysis->results['ai_analysis']['summary'] }}</p><ul>@foreach($analysis->results['ai_analysis']['recommendations'] as $tip)<li>{{ $tip }}</li>@endforeach</ul></section>@endif
        <div class="checker-keywords mt-6">
            @foreach(['matched' => 'Matching requirements', 'missing' => 'Missing requirements'] as $key => $heading)
                <section class="checker-panel"><h2>{{ $heading }}</h2><div class="checker-tags">@forelse($analysis->results[$key] ?? [] as $word)<span>{{ $word }}</span>@empty<p>No keywords recorded.</p>@endforelse</div></section>
            @endforeach
        </div>
        <section class="checker-panel mt-6"><h2>Job description</h2><p class="whitespace-pre-wrap break-words">{{ $analysis->job_description }}</p></section>
    </section>
</x-app-layout>
