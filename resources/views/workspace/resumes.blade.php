<x-app-layout>
    <div class="dashboard-heading"><div><span class="eyebrow">YOUR CAREER STORY</span><h1>My CVs<span class="blue-text">.</span></h1><p>Pick up where you left off, or start a new chapter.</p></div><a class="btn btn-primary" href="{{ route('builder.start', ['new' => 1]) }}">Create a CV <x-icon name="plus" /></a></div>
    <section class="panel">
        @forelse($resumes as $resume)
            <div class="record-row"><span class="icon-box"><x-icon name="file-text" /></span><div><strong>{{ $resume->name }}</strong><small>Updated {{ $resume->updated_at->format('d M Y') }}</small></div><a class="text-link" href="{{ route('builder.edit', $resume) }}">Edit CV <x-icon name="arrow-up-right" /></a></div>
        @empty
            <div class="empty-state"><h2>Your next CV starts here.</h2><p>Choose a template and tell your story.</p><a class="text-link" href="{{ route('builder.start', ['new' => 1]) }}">Create your first CV →</a></div>
        @endforelse
        {{ $resumes->links() }}
    </section>
</x-app-layout>
