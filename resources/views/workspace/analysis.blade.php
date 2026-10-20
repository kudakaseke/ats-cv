<x-app-layout>
    <div class="dashboard-heading"><div><h1>CV comparison</h1><p>{{ $analysis->created_at->format('d M Y, H:i') }} · Saved result</p></div><a class="btn btn-outline" href="{{ route('workspace.module', 'history') }}">Back to saved checks</a></div>
    <section class="ats-checker">
        <div class="checker-score-panel"><strong class="checker-score">{{ $analysis->results['similarity_percent'] ?? $analysis->score }}%</strong><div><h2>Text similarity</h2><p>This is a comparison with the supplied job description, not an employer's ATS decision.</p></div></div>
        @if(isset($analysis->results['ai_analysis']))<section class="checker-panel mt-6"><h2>AI recommendations</h2><p>{{ $analysis->results['ai_analysis']['summary'] }}</p><ul>@foreach($analysis->results['ai_analysis']['recommendations'] as $tip)<li>{{ $tip }}</li>@endforeach</ul></section>@endif
        <div class="checker-keywords mt-6">
            @foreach(['matched' => 'Matched keywords', 'missing' => 'Missing keywords'] as $key => $heading)
                <section class="checker-panel"><h2>{{ $heading }}</h2><div class="checker-tags">@forelse($analysis->results[$key] ?? [] as $word)<span>{{ $word }}</span>@empty<p>No keywords recorded.</p>@endforelse</div></section>
            @endforeach
        </div>
        <section class="checker-panel mt-6"><h2>Job description</h2><p class="whitespace-pre-wrap break-words">{{ $analysis->job_description }}</p></section>
    </section>
</x-app-layout>
