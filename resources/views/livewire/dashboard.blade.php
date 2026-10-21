<div>
    @if(session('payment_success'))<section class="panel" role="status">{{ session('payment_success') }}</section>@endif
    @php($wallet = app(\App\Services\AiCreditService::class)->wallet(auth()->user()))
    <div class="dashboard-action-grid" aria-label="Dashboard tools">
        @foreach ([
            ['ATS Scanner', 'scan-text', route('workspace.module', 'checker'), 'Compare your CV with a job description.'],
            ['Create CV', 'file-plus-2', route('builder.start', ['new' => 1]), 'Build your next CV step by step.'],
            ['View Templates', 'layout-template', route('builder.start', ['templates' => 1]), 'Explore the CV designs and choose your favourite.'],
            ['Plan', 'gem', route('subscription'), 'View your plan and available options.'],
            ['Settings', 'user-round', route('profile.edit'), 'Manage your account details.'],
            ['ATS Detect', 'file-check-2', route('workspace.module', 'detect'), 'Free basic check without a job description.'],
        ] as [$label, $icon, $url, $description])
            <a class="dashboard-action-card" href="{{ $url }}">
                <span class="dashboard-action-icon"><x-icon :name="$icon" /></span>
                <h2>{{ $label }}</h2>
                <p>{{ $description }}</p>
                <span class="dashboard-action-arrow" aria-hidden="true"><x-icon name="arrow-up-right" /></span>
            </a>
        @endforeach
    </div>
    <section class="dashboard-credit-card" aria-label="AI credit balance">
        <div class="dashboard-credit-intro"><span class="dashboard-credit-label">YOUR AI CREDITS</span><h2>Make your next move.</h2><p>Check your match. Tailor your CV.</p><a class="btn btn-primary" href="{{ route('subscription') }}#credit-topups">Add credits <x-icon name="arrow-up-right" /></a></div>
        <div class="dashboard-credit-details"><div class="dashboard-credit-balance" aria-label="{{ $wallet['credits'] }} credits"><strong>{{ $wallet['credits'] }}</strong><span>credits ready to use</span></div><div class="dashboard-credit-rates"><span>ATS Detect <strong>5 credits</strong></span><span>CV revamp <strong>10 credits</strong></span><span>Editing &amp; downloads <strong>Always free</strong></span></div></div>
    </section>
</div>
