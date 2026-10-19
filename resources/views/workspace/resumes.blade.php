<x-app-layout>
    <div class="dashboard-heading"><div><h1>My CVs</h1><p>Open a saved CV to edit it, or create a new one.</p></div><a class="btn btn-primary" href="{{ route('builder.start', ['new' => 1]) }}">Create a CV <x-icon name="plus" /></a></div>
    <section class="panel">
        @if(session('status'))<p class="p-4" role="status">{{ session('status') }}</p>@endif
        @forelse($resumes as $resume)
            <div class="record-row"><span class="icon-box"><x-icon name="file-text" /></span><div><strong>{{ $resume->name }}</strong><small>Updated {{ $resume->updated_at->format('d M Y') }}</small></div><div class="record-actions"><a class="text-link" href="{{ route('builder.edit', $resume) }}">Edit CV</a><form method="POST" action="{{ route('builder.destroy', $resume) }}" @submit="if (!confirm('Delete this CV? This cannot be undone.')) $event.preventDefault()">@csrf @method('DELETE')<button class="text-link" type="submit">Delete</button></form></div></div>
        @empty
            <div class="empty-state"><h2>No saved CVs yet</h2><p>Create and save a CV to see it here.</p><a class="text-link" href="{{ route('builder.start', ['new' => 1]) }}">Create your first CV →</a></div>
        @endforelse
        {{ $resumes->links() }}
    </section>
</x-app-layout>
