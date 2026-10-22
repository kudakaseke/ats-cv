<x-public-layout>
    <section class="reference-hero" id="home">
        <div class="reference-hero-content">
            <h1>Make Your CV <span>Stand Out with AI</span></h1>
            <p>Check your CV against a job, improve it with AI,<br class="desktop-break"> and build a professional resume for your next move.</p>
            <div class="reference-hero-actions">
                <a class="reference-start" href="{{ route('builder.start', ['questions' => 1]) }}">Get Started Free</a>
                <a class="reference-explore" href="{{ route('features') }}">Explore Features</a>
                <a class="reference-check" href="{{ auth()->check() ? route('workspace.module', 'checker') : route('login') }}"><x-icon name="upload" /> Check My CV</a>
            </div>
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





    <section class="reference-faq" id="faq" aria-labelledby="faq-heading">
        <div class="reference-container reference-faq-grid">
            <div><span class="reference-eyebrow">GOOD QUESTIONS. CLEAR ANSWERS.</span><h2 id="faq-heading">A little clarity.<br><span>A confident start.</span></h2><a class="reference-inline-link" href="{{ route('contact') }}">Still have a question?</a></div>
            <div class="reference-faq-list">
                @foreach ([['What is an ATS CV Checker?', 'It compares the information in your CV with a job description and suggests ways to make your application clearer. It does not access an employer’s ATS.'], ['How is the match percentage calculated?', 'The checker compares the wording in your CV with the job description. It shows matching skills and requirements that are missing from your CV. The percentage is a guide, not an employer?s ATS score or a guarantee of employment.'], ['Can AI improve my CV?', 'The CV Checker offers optional AI rewriting to improve wording and organisation using your existing information. Unsupported requirements will appear separately as gaps. You review suggestions before saving.'], ['Can I download my CV as PDF or Word?', 'Yes. Review your CV in the builder, then choose Download and select PDF or editable Word (.docx).'], ['Is my personal information secure?', 'Account access uses authentication and ownership checks. Saved CVs are stored for editing. AI analysis and rewriting send your CV text and job description to OpenAI when you use those features. Read our Privacy Policy for details and contact support for access or deletion requests.'], ['Can I use the system for free?', 'Registration and the dashboard are free. Your account receives 15 welcome credits once: enough for one Job-to-CV ATS Detector check and revamp, or three Job-to-CV ATS Detector checks. The Free plan includes one saved CV. Paid plans provide monthly credits, and you can buy additional credits.']] as $faq)
                    <details><summary>{{ $faq[0] }}<x-icon name="plus" /></summary><p>{{ $faq[1] }}</p></details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="reference-final-cta"><div class="reference-container"><h2>Your next chapter.<br><span>A better introduction.</span></h2><a class="reference-plan-link" href="{{ route('builder.start', ['questions' => 1]) }}">Get Started Free </a></div></section>
</x-public-layout>
