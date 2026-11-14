<x-app-layout><livewire:dashboard />
    <section class="mx-auto my-8 max-w-7xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-800">Share feedback</h2>
        <p class="mt-1 text-sm text-slate-600">Send a review, suggestion, complaint, or rating to the ATS CV Detector team.</p>
        @if(session('status'))<p role="status" class="mt-3 text-sm text-green-700">{{ session('status') }}</p>@endif
        <form method="POST" action="{{ route('feedback.submit') }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf
            <label class="grid gap-1 text-sm">Category<select name="category" required class="rounded-md border-slate-300"><option value="cv_analysis">CV analysis</option><option value="account">Account</option><option value="billing">Billing</option><option value="complaint">Complaint</option><option value="suggestion">Suggestion</option><option value="other">Other</option></select></label>
            <label class="grid gap-1 text-sm">Rating (optional)<select name="rating" class="rounded-md border-slate-300"><option value="">No rating</option>@for($i=5;$i>=1;$i--)<option value="{{ $i }}">{{ $i }} / 5</option>@endfor</select></label>
            <label class="grid gap-1 text-sm sm:col-span-2">Your feedback<textarea name="body" required minlength="10" maxlength="3000" rows="4" class="rounded-md border-slate-300"></textarea></label>
            <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="testimonial_consent" value="1" class="mt-1 rounded border-slate-300"><span>I consent to this review being considered for a public testimonial if approved.</span></label>
            <div class="sm:col-span-2"><button class="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Send feedback</button></div>
        </form>
    </section>
</x-app-layout>
