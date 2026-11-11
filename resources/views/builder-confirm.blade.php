<x-public-layout :title="$action === 'new' ? 'Start a new CV' : 'Save your CV'">
    <section class="reference-container policy-document">
        <h1>{{ $action === 'new' ? 'Start a new CV?' : 'Save your CV to your account?' }}</h1>
        <p>{{ $action === 'new' ? 'This will replace your current unfinished draft. Your saved CVs will remain available.' : 'Your draft is ready to save. You can continue editing it afterwards.' }}</p>
        <form method="POST" action="{{ $action === 'new' ? route('builder.new') : route('builder.finish.store') }}">
            @csrf
            <button type="submit" class="btn btn-primary">{{ $action === 'new' ? 'Start a new CV' : 'Save my CV' }}</button>
            <a class="btn btn-outline" href="{{ route('builder.start') }}">Continue editing current draft</a>
        </form>
    </section>
</x-public-layout>
