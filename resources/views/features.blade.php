<x-public-layout title="Features">
    <section class="reference-features" id="features" aria-labelledby="features-heading">
        <div class="reference-container">
            <div class="reference-section-heading">
                <div><span class="reference-eyebrow">THE TOOLS FOR YOUR NEXT MOVE</span><h1 class="page-section-heading" id="features-heading">Find the gaps.<br><span>Show your strengths.</span></h1></div>
                <p>Check the CV you have, improve it for a job,<br>or build your next one from scratch.</p>
            </div>
            <div class="reference-feature-grid">
                <article class="reference-feature-card feature-checker">
                    <span class="feature-category">Know where you stand</span>
                    <h3>Check your CV.<br>Find your next step.</h3>
                    <div class="feature-meta"><span><x-icon name="file-text" /> Upload PDF or Word</span><span><x-icon name="scan-text" /> Compare with a job advert</span></div>
                    <p class="feature-highlight">See what matches and what needs work</p>
                    <ul class="feature-details"><li>See your match against job requirements</li><li>Spot missing qualifications, skills and competencies</li><li>Compare required experience with your CV</li><li>No job advert? Run basic ATS Checker checks</li></ul>
                    <div class="feature-card-footer"><a href="{{ auth()->check() ? route('workspace.module', 'checker') : route('login') }}">Check My CV </a><p>Apply with<br><span>More clarity</span></p></div>
                </article>
                <article class="reference-feature-card feature-revamp">
                    <span class="feature-category">Tailored to the job</span>
                    <h3>Make your<br>experience count.</h3>
                    <div class="feature-meta"><span><x-icon name="wand-sparkles" /> AI-powered CV revamp</span><span><x-icon name="shield-check" /> Built on your real experience</span></div>
                    <p class="feature-highlight">Turn relevant experience into a clearer CV</p>
                    <ul class="feature-details"><li>Focus your summary on the role you want</li><li>Use job-relevant skills supported by your experience</li><li>Sharpen responsibilities and achievement descriptions</li><li>Review and edit every change before downloading</li></ul>
                    <div class="feature-card-footer"><a href="{{ auth()->check() ? route('workspace.module', 'revamp') : route('login') }}">Tailor My CV </a><p>Your experience.<br><span>Stronger wording.</span></p></div>
                </article>
                <article class="reference-feature-card feature-builder">
                    <span class="feature-category">A fresh start, made simple</span>
                    <h3>Create a CV.<br>Make it yours.</h3>
                    <div class="feature-meta"><span><x-icon name="file-pen-line" /> Edit directly on your CV</span><span><x-icon name="layout-template" /> Templates by department</span></div>
                    <p class="feature-highlight">From a blank page to a CV ready to share</p>
                    <ul class="feature-details"><li>Choose from {{ count(config('cv_templates')) }} professional templates</li><li>Import an existing CV or start from scratch</li><li>Add sections and preview your finished pages</li><li>Manual editing and PDF or Word downloads are free</li></ul>
                    <div class="feature-card-footer"><a href="{{ route('builder.start', ['questions' => 1]) }}">Create My CV </a><p>Start now.<br><span>Sign in to save.</span></p></div>
                </article>
            </div>
            <p class="reference-release-note">Start building without an account, then sign in to save. New accounts receive 15 welcome credits for one Job-to-CV ATS Detector check and CV revamp. Manual editing and downloads are free.</p>
        </div>
    </section>
</x-public-layout>
