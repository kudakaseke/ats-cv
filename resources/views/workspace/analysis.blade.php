<x-app-layout>
    <div class="dashboard-heading"><div><h1>CV comparison</h1><p>{{ $analysis->created_at->format('d M Y, H:i') }} · Saved result</p></div><a class="btn btn-outline" href="{{ route('workspace.module', 'history') }}">Back to saved checks</a></div>
    <section class="ats-checker">
        @if(!empty($analysis->results['green_flags']))<section class="checker-green-flags"><h3>Qualification strengths</h3><ul>@foreach($analysis->results['green_flags'] as $flag)<li>{{ $flag }}</li>@endforeach</ul>@if($analysis->results['certificate_bonus'] ?? 0)<p>Includes {{ $analysis->results['certificate_bonus'] }} additional certificate bonus points.</p>@endif</section>@endif
        <div class="checker-score-panel"><strong class="checker-score">@if(isset($analysis->results['keyword_score'])){{ $analysis->results['keyword_score'] }}/100 @else{{ $analysis->results['similarity_percent'] ?? $analysis->score }}% @endif</strong><div><h2>{{ isset($analysis->results['keyword_score']) ? 'CV and job requirements match' : 'Wording similarity' }}</h2><p>Review the matched requirements and any gaps in your CV.</p></div></div>
        @if(isset($analysis->results['experience_check']))<section class="checker-experience-warning"><h3>Experience requirement</h3><p>{{ $analysis->results['experience_check']['message'] }}</p><small>{{ $analysis->results['experience_check']['note'] }}</small></section>@endif
        @if(isset($analysis->results['ai_analysis']))<section class="checker-panel mt-6"><h2>AI recommendations</h2><p>{{ $analysis->results['ai_analysis']['summary'] }}</p><ul>@foreach($analysis->results['ai_analysis']['recommendations'] as $tip)<li>{{ $tip }}</li>@endforeach</ul></section>@endif
        <div class="checker-keywords mt-6">
            @foreach(['matched' => 'Matching requirements', 'missing' => 'Missing requirements'] as $key => $heading)
                <section class="checker-panel"><h2>{{ $heading }}</h2><div class="checker-tags">@forelse($analysis->results[$key] ?? [] as $word)<span>{{ $word }}</span>@empty<p>No keywords recorded.</p>@endforelse</div></section>
            @endforeach
        </div>
        <section class="checker-panel mt-6"><h2>Job description</h2><p class="whitespace-pre-wrap break-words">{{ $analysis->job_description }}</p></section>
    </section>
</x-app-layout>
