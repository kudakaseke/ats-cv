<x-public-layout>
    <section class="reference-hero" id="home">
        <div class="reference-hero-content">
            <h1>Make Your CV <span>Stand Out with AI</span></h1>
            <p>Check your CV against a job, improve it with AI,<br class="desktop-break"> and build a professional resume for your next move.</p>
            <div class="reference-hero-actions">
                <a class="reference-start" href="{{ route('register') }}">Get Started Free <span><x-icon name="arrow-up-right" /></span></a>
                <a class="reference-explore" href="#features">Explore Features <x-icon name="arrow-down-right" /></a>
                <a class="reference-check" href="{{ auth()->check() ? route('workspace.module', 'checker') : route('login') }}"><x-icon name="upload" /> Check My CV</a>
            </div>
        </div>
    </section>

    <section class="reference-features" id="features" aria-labelledby="features-heading">
        <div class="reference-container">
            <div class="reference-section-heading">
                <div><span class="reference-eyebrow">THE TOOLS FOR YOUR NEXT MOVE</span><h2 id="features-heading">Your experience.<br><span>A clearer introduction.</span></h2></div>
                <p>Understand your match.<br>Refine your story. Build your next CV.</p>
            </div>
            <div class="reference-feature-grid">
                <article class="reference-feature-card feature-checker">
                    <span class="feature-category">CV & job matching</span>
                    <h3>AI ATS<br>CV Checker</h3>
                    <div class="feature-meta"><span><x-icon name="file-text" /> PDF & DOCX uploads</span><span><x-icon name="scan-text" /> Job-description matching</span></div>
                    <p class="feature-highlight">Understand where you stand</p>
                    <ul class="feature-details"><li>A transparent estimated match score</li><li>Matching skills and missing requirements</li><li>Clear recommendations for improvement</li><li>Saved results and analysis history</li></ul>
                    <div class="feature-card-footer"><a href="{{ auth()->check() ? route('workspace.module', 'checker') : route('login') }}">Explore <span><x-icon name="chevron-right" /></span></a><p>Made for<br><span>Clearer applications</span></p></div>
                </article>
                <article class="reference-feature-card feature-revamp">
                    <span class="feature-category">AI-powered improvement</span>
                    <h3>AI CV<br>Revamp</h3>
                    <div class="feature-meta"><span><x-icon name="wand-sparkles" /> Sharper, clearer wording</span><span><x-icon name="shield-check" /> Your real experience</span></div>
                    <p class="feature-highlight">Give your strengths a stronger voice</p>
                    <ul class="feature-details"><li>A more focused professional summary</li><li>Stronger experience and project descriptions</li><li>An editable preview for your review</li><li>Separate versions, with your original preserved</li></ul>
                    <div class="feature-card-footer"><a href="{{ auth()->check() ? route('workspace.module', 'revamp') : route('login') }}">Explore <span><x-icon name="chevron-right" /></span></a><p>Grounded in<br><span>Your own story</span></p></div>
                </article>
                <article class="reference-feature-card feature-builder">
                    <span class="feature-category">Professional CV Builder</span>
                    <h3>Your next CV.<br>Built around you.</h3>
                    <div class="feature-meta"><span><x-icon name="file-pen-line" /> Step-by-step guidance</span><span><x-icon name="layout-template" /> ATS-friendly templates</span></div>
                    <p class="feature-highlight">From a fresh start to a finished CV</p>
                    <ul class="feature-details"><li>Personal details, experience, and education</li><li>Skills, certifications, projects, and references</li><li>Saved drafts and tailored CV versions</li><li>PDF and editable Word downloads</li></ul>
                    <div class="feature-card-footer"><a href="{{ auth()->check() ? route('workspace.module', 'builder') : route('login') }}">Explore <span><x-icon name="chevron-right" /></span></a><p>Designed for<br><span>Your next chapter</span></p></div>
                </article>
            </div>
            <p class="reference-release-note">Early access: accounts and your dashboard are available. CV analysis, revamping, building, and downloads are coming in later releases.</p>
        </div>
    </section>

    <section class="reference-process" id="how-it-works" aria-labelledby="process-heading">
        <div class="reference-container">
            <span class="reference-eyebrow">A SIMPLE WAY FORWARD</span>
            <h2 id="process-heading">Four steps.<br><span>Your next opportunity.</span></h2>
            <ol class="reference-steps">
                @foreach ([['Upload your CV', 'Start with your existing PDF or Word document.'], ['Paste the job description', 'Add the responsibilities, skills, and qualifications for the role.'], ['Understand your match', 'Review your strengths, gaps, and practical next steps.'], ['Refine or create your CV', 'Review your changes and prepare a professional version.']] as $step)
                    <li><span class="reference-step-number">0{{ $loop->iteration }}</span><h3>{{ $step[0] }}</h3><p>{{ $step[1] }}</p></li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="reference-about" id="about" aria-labelledby="about-heading">
        <div class="reference-about-copy">
            <span class="reference-eyebrow">PEOPLE FIRST, POTENTIAL FORWARD</span>
            <h2 id="about-heading">Your Journey<br>Towards <span>Confidence</span><br>and <span>Opportunity</span></h2>
            <p>ATS CV helps job seekers understand what a role needs and present their experience with clarity. Our approach puts people, simplicity, and integrity at the centre of every career move. Compare your CV with a job description, find practical ways to improve it, and build a professional resume that reflects who you are. AI supports your wording and organisation while your skills, qualifications, and achievements remain your own. You stay in control of your information, review every suggested change, and choose how to tell your story.</p>
        </div>
        <div class="reference-about-stats" aria-label="Our approach">
            <div class="reference-about-stat"><strong>3</strong><span>Career tools</span></div>
            <div class="reference-about-stat stat-blue"><strong>1</strong><span>Your authentic story</span></div>
        </div>
    </section>

    <section class="reference-pricing" id="pricing" aria-labelledby="pricing-heading">
        <div class="reference-container">
            <div class="reference-section-heading"><div><span class="reference-eyebrow">A PLAN FOR YOUR NEXT STEP</span><h2 id="pricing-heading">Start free.<br><span>Move forward.</span></h2></div><p>Support that fits your ambition.<br>No credit card needed to get started.</p></div>
            <div class="reference-plan-grid">
                @foreach ($plans as $plan)
                    <article class="reference-plan">
                        <h3>{{ $plan->name }}</h3>
                        <p class="reference-price">${{ number_format($plan->price_cents / 100, 0) }}<span>/ month</span></p>
                        <ul>@foreach ($plan->features as $feature)<li><x-icon name="check" /> {{ $feature }}</li>@endforeach</ul>
                        <a class="reference-plan-link" href="{{ $plan->price_cents === 0 ? route('register') : route('contact') }}">{{ $plan->price_cents === 0 ? 'Get Started Free' : 'Enquire about '.$plan->name }} <span><x-icon name="chevron-right" /></span></a>
                        <small>{{ $plan->price_cents > 0 ? 'Planned pricing. Paid upgrades are not active.' : 'No credit card required.' }}</small>
                    </article>
                @endforeach
            </div>
            <p class="reference-release-note">Prices in USD. Paid plans and live payments will be available in a future release.</p>
        </div>
    </section>

    <section class="reference-faq" id="faq" aria-labelledby="faq-heading">
        <div class="reference-container reference-faq-grid">
            <div><span class="reference-eyebrow">GOOD QUESTIONS. CLEAR ANSWERS.</span><h2 id="faq-heading">A little clarity.<br><span>A confident start.</span></h2><a class="reference-inline-link" href="{{ route('contact') }}">Still have a question? <x-icon name="arrow-up-right" /></a></div>
            <div class="reference-faq-list">
                @foreach ([['What is an ATS CV Checker?', 'It compares the information in your CV with a job description and suggests ways to make your application clearer. It does not access an employer’s ATS.'], ['How is the match percentage calculated?', 'The planned scoring model weights required skills at 35%, experience at 25%, education at 15%, responsibilities at 15%, and readability at 10%. AI extracts evidence; rule-based logic calculates the estimate. It is not an official ATS score or a guarantee of employment.'], ['Can AI improve my CV?', 'The revamp tool will improve wording and organisation using your existing information. Unsupported requirements will appear separately as gaps. You review suggestions before saving.'], ['Can I download my CV as PDF or Word?', 'Searchable PDF and editable DOCX downloads are planned for the document-generation release, using the same clean, ATS-friendly template.'], ['Is my personal information secure?', 'Account access is protected by authentication. Document features will use private storage and ownership checks, and request consent before sharing CV content with Gemini. You can delete your account from your profile.'], ['Can I use the system for free?', 'Registration and the dashboard are free. The Free plan includes three monthly analyses and one saved CV once those tools are released. Paid upgrades are currently disabled.']] as $faq)
                    <details><summary>{{ $faq[0] }}<x-icon name="plus" /></summary><p>{{ $faq[1] }}</p></details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="reference-final-cta"><div class="reference-container"><h2>Your next chapter.<br><span>A better introduction.</span></h2><a class="reference-plan-link" href="{{ route('register') }}">Get Started Free <span><x-icon name="arrow-up-right" /></span></a></div></section>
</x-public-layout>
