<x-app-layout>
    <div class="dashboard-heading"><div><h1>Drafts</h1><p>Pick up where you left off and keep working on your CV.</p></div><a class="btn btn-primary" href="{{ route('builder.start', ['new' => 1]) }}">Create a CV <x-icon name="plus" /></a></div>
    <section class="panel" aria-label="CV drafts">
        @php($hasCurrent = !empty($currentDraft['data']) && empty($currentDraft['resume_id']))
        @if($hasCurrent)
            <div class="record-row"><span class="icon-box"><x-icon name="file-pen-line" /></span><div><strong>{{ $currentDraft['data']['name'] ?? 'Untitled' }} CV</strong><small>Current browser draft · Save it in the builder to keep it in your account.</small></div><div class="record-actions"><a class="text-link" href="{{ route('builder.start') }}">Continue draft</a></div></div>
        @endif
        @foreach($drafts as $draft)
            <div class="record-row"><span class="icon-box"><x-icon name="file-text" /></span><div><strong>{{ $draft->name }}</strong><small>Saved {{ $draft->updated_at->format('d M Y') }}</small></div><div class="record-actions"><a class="text-link" href="{{ route('builder.edit', $draft) }}">Continue draft</a></div></div>
        @endforeach
        @if(!$hasCurrent && $drafts->isEmpty())
            <div class="empty-state"><h2>No drafts yet</h2><p>Your saved drafts and your current unfinished CV will appear here.</p><a class="text-link" href="{{ route('builder.start', ['new' => 1]) }}">Start a CV</a></div>
        @endif
        {{ $drafts->links() }}
    </section>
</x-app-layout>
