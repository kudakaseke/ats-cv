<div>
    <div class="dashboard-heading"><div><h1>Dashboard</h1></div></div>
    @if(session('payment_success'))<section class="panel" role="status">{{ session('payment_success') }}</section>@endif
    <x-payment-confirmation />
    @php($wallet = app(\App\Services\AiCreditService::class)->wallet(auth()->user()))
    <section class="panel credit-summary"><h2>My AI Credits</h2><strong>{{ $wallet['credits'] }} credits</strong><p>Analysis: 5 credits &middot; CV revamp: 10 credits &middot; Manual editing and downloads: free.</p><a class="text-link" href="{{ route('subscription') }}#credit-topups">Buy more credits</a></section>
    <div class="dashboard-action-grid" aria-label="Dashboard tools">
        @foreach ([
            ['ATS Scanner', 'scan-text', route('workspace.module', 'checker'), 'Compare your CV with a job description.'],
            ['Create CV', 'file-plus-2', route('builder.start', ['new' => 1]), 'Build your next CV step by step.'],
            ['View Templates', 'layout-template', route('builder.start', ['templates' => 1]), 'Explore the CV designs and choose your favourite.'],
            ['Plan', 'gem', route('subscription'), 'View your plan and available options.'],
            ['Settings', 'user-round', route('profile.edit'), 'Manage your account details.'],
        ] as [$label, $icon, $url, $description])
            <a class="dashboard-action-card" href="{{ $url }}">
                <span class="dashboard-action-icon"><x-icon :name="$icon" /></span>
                <h2>{{ $label }}</h2>
                <p>{{ $description }}</p>
                <span class="dashboard-action-arrow" aria-hidden="true"><x-icon name="arrow-up-right" /></span>
            </a>
        @endforeach
    </div>
</div>
