<div class="workspace-dashboard">
    @if(session('payment_success'))<section class="panel" role="status">{{ session('payment_success') }}</section>@endif
    @php($wallet = app(\App\Services\AiCreditService::class)->displayWallet(auth()->user()))
    <header class="dashboard-welcome"><div><span>Your workspace</span><h1>Your next move.<br><em>Starts here.</em></h1></div><p>Check the CV you have, tailor it to a job,<br>or start something new.</p></header>
    <div class="dashboard-action-grid reference-feature-grid" aria-label="Dashboard tools">
        @foreach ([
            ['Drafts', 'file-pen-line', route('workspace.module', 'drafts'), 'Find your saved drafts and continue the CV you are working on.', 'Ready when you are', 'Open drafts', 'feature-builder'],
            ['Job-to-CV ATS Detector', 'scan-text', route('workspace.module', 'checker'), 'Compare your CV with a job description. Find the gaps, then tailor your experience to the role.', 'Know where you stand', 'Check my CV', 'feature-checker'],
            ['Create CV', 'file-plus-2', route('builder.start', ['new' => 1]), 'Start fresh or bring your existing CV. Make it yours, then download as PDF or Word.', 'A fresh start', 'Create my CV', 'feature-builder'],
            ['View Templates', 'layout-template', route('builder.start', ['templates' => 1]), 'Find a layout that suits your profession and lets your experience do the talking.', 'Find your style', 'Explore templates', 'feature-builder'],
            ['Plan', 'gem', route('subscription'), 'See your current plan, check your options and add credits when you need them.', 'Room for your next step', 'View my plan', 'feature-revamp'],
            ['Settings', 'user-round', route('profile.edit'), 'Keep your account details up to date and manage how you sign in.', 'The details that matter', 'Manage my account', 'feature-builder'],
            ['ATS Checker', 'file-check-2', route('workspace.module', 'detect'), 'Check the structure and readability of your CV. No job description needed, always free.', 'Start with the basics', 'Check my layout', 'feature-checker'],
        ] as [$label, $icon, $url, $description, $category, $action, $tone])
            <article class="reference-feature-card dashboard-tool-card {{ $tone }}">
                <span class="feature-category">{{ $category }}</span>
                <h2>{{ $label }}</h2>
                <span class="dashboard-tool-symbol" aria-hidden="true"><x-icon :name="$icon" /></span>
                <p>{{ $description }}</p>
                <div class="feature-card-footer"><a href="{{ $url }}">{{ $action }} <span><x-icon name="arrow-up-right" /></span></a></div>
            </article>
        @endforeach
    </div>
    <section class="dashboard-credit-card" aria-label="AI credit balance">
        <div class="dashboard-credit-intro"><span class="dashboard-credit-label">YOUR AI CREDITS</span><h2>Make your next move.</h2><p>Check your match. Tailor your CV.</p><a class="btn btn-primary" href="{{ route('subscription') }}#credit-topups">Add credits</a></div>
        <div class="dashboard-credit-details"><div class="dashboard-credit-balance" aria-label="{{ $wallet['credits'] }} credits"><strong>{{ $wallet['credits'] }}</strong><span>credits ready to use</span></div><div class="dashboard-credit-rates"><span>Job-to-CV ATS Detector <strong>5 credits</strong></span><span>CV revamp <strong>10 credits</strong></span><span>Editing &amp; downloads <strong>Always free</strong></span></div></div>
    </section>
</div>
