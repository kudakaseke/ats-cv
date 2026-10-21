<x-app-layout>
    <div class="dashboard-heading"><div><h1>Saved checks</h1><p>Review your previous CV comparisons. Review keyword matches and experience requirements for each job.</p></div><a class="btn btn-primary" href="{{ route('workspace.module', 'checker') }}">Check a CV</a></div>
    <section class="panel">
        @forelse($analyses as $analysis)
            <div class="record-row"><span class="icon-box"><x-icon name="scan-text" /></span><div><strong>{{ $analysis->job_title ?: 'CV comparison' }}</strong><small>{{ $analysis->created_at->format('d M Y, H:i') }} · @if(isset($analysis->results['keyword_score'])){{ $analysis->results['keyword_score'] }}/100 keyword match @else{{ $analysis->results['similarity_percent'] ?? $analysis->score }}% wording similarity @endif</small></div><a class="text-link" href="{{ route('workspace.analysis', $analysis) }}">View result</a></div>
        @empty
            <div class="empty-state"><h2>No saved checks yet</h2><p>Your completed CV comparisons will appear here.</p></div>
        @endforelse
        <div class="p-4">{{ $analyses->links() }}</div>
    </section>
</x-app-layout>
