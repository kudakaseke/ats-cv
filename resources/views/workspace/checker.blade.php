<x-app-layout>
    <section class="ats-checker" x-data="cvChecker({{ Js::from(['csrf' => csrf_token(), 'scanUrl' => route('checker.scan'), 'uploadUrl' => route('checker.upload'), 'rewriteUrl' => route('checker.rewrite'), 'credits' => app(\App\Services\AiCreditService::class)->displayWallet(auth()->user())['credits']]) }})">
        <header class="scanner-compact-header"><h1>Job-to-CV ATS Detector</h1><div><span><strong x-text="credits"></strong> credits</span><a class="text-link" href="{{ route('subscription') }}#credit-topups">Top up</a></div></header>
        <p class="scanner-costs">Job-to-CV ATS Detector &middot; 5 credits <span aria-hidden="true">/</span> Revamp &middot; 10 credits</p>
        <ol class="checker-steps" aria-label="Resume scan progress">
            <template x-for="(label, index) in ['Job description', 'Upload resume', 'ATS scan', 'View results']" :key="label">
                <li :class="{ active: step === index + 1, complete: step > index + 1 }" :aria-current="step === index + 1 ? 'step' : null"><span x-text="index + 1"></span><strong x-text="label"></strong></li>
            </template>
        </ol>
        <p x-show="error && !modalOpen" x-cloak x-text="error" class="checker-error" role="alert"></p>
        <section x-show="step === 1" class="checker-job-section">
            <h2>Paste the job description</h2>
            <label class="sr-only" for="checker-job">Job description</label><textarea id="checker-job" x-model="job" rows="3" maxlength="20000" placeholder="Paste the responsibilities, requirements and skills here..."></textarea>
            <div class="checker-actions"><button type="button" class="btn btn-primary" @click="nextJob()">Next: Upload resume</button></div>
            <p class="scanner-basic-link">No job description? <a class="text-link" href="{{ route('workspace.module', 'detect') }}">Try a free basic CV check</a>.</p>
        </section>
        <section x-show="step === 2" x-cloak>
            <div class="checker-drop" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; upload($event.dataTransfer.files[0])">
                <span class="checker-upload-icon"><x-icon name="upload" /></span><h2>Drag and drop a file here</h2><p>Files we can read: PDF, DOCX, TXT &middot; Up to 5 MB</p>
                <input type="file" x-ref="file" class="sr-only" aria-label="Resume file" accept=".pdf,.docx,.txt" @change="upload($event.target.files[0]); $event.target.value = ''" :disabled="busy">
                <button type="button" class="btn btn-primary" @click="$refs.file.click()" :disabled="busy" x-text="busy ? 'Reading resume...' : 'Upload Your Resume'"></button>
                <p x-show="filename" x-text="filename" class="checker-filename" role="status"></p>
            </div>
            <details class="checker-paste" :open="!!resumeText"><summary>Or paste resume text</summary><label for="checker-resume">Resume text &mdash; review or paste your CV</label><textarea id="checker-resume" x-model="resumeText" rows="12" maxlength="20000" placeholder="Paste your CV text here..."></textarea></details>
            <div class="checker-actions"><button class="btn btn-outline" @click="step = 1; error = ''" :disabled="busy">Back</button><button class="btn btn-primary" @click="nextResume()" :disabled="busy">Next: ATS scan</button></div>
        </section>
        <section x-show="step === 3" x-cloak class="checker-panel checker-scan">
            <span class="checker-upload-icon"><x-icon name="scan-text" /></span><h2>Compare your CV to the job</h2><p>We will find matching and missing keywords from your job description.</p>
            <div class="checker-actions"><button class="btn btn-outline" @click="step = 2" :disabled="busy">Back</button><button class="btn btn-primary" @click="scan()" :disabled="busy"><x-icon name="scan-text" /><span x-text="busy ? 'Scanning...' : 'ATS Scan'"></span></button></div>
        </section>
        <section x-show="step === 4 && result" x-cloak>
            <x-score-pair :dynamic="true" />


            @include('workspace.partials.qualification-green-flags')
            <section class="checker-experience-warning" x-show="result?.experience_check?.required_years != null"><h3 x-text="result?.experience_check?.shortfall ? 'Experience gap to review' : 'Experience requirement'"></h3><p x-text="result?.experience_check?.message"></p><small x-text="result?.experience_check?.note"></small></section>
            @include('workspace.partials.checker-findings')
            <p><a class="text-link" href="{{ route('workspace.module', 'history') }}">View saved checks</a></p>
            <div class="checker-actions"><button class="btn btn-outline" @click="step = 1; error = ''" :disabled="busy">Change job description</button><button class="btn btn-primary" @click="fix()" :disabled="busy"><x-icon name="wand-sparkles" /><span x-text="busy ? 'Fixing resume...' : 'Fix Resume'"></span></button></div>
            <form method="POST" action="{{ route('checker.edit') }}" class="checker-export">@csrf<input type="hidden" name="resume_text" :value="resumeText"><button class="btn btn-outline" type="submit" :disabled="busy"><x-icon name="file-pen-line" />Review original CV</button></form>
        </section>
        <dialog x-ref="scanDialog" class="checker-result-dialog" aria-labelledby="checker-result-title" @cancel="if (busy) $event.preventDefault()" @close="modalOpen = false">
            <div class="checker-dialog-content" :aria-busy="busy">
                <button type="button" class="checker-dialog-close" aria-label="Close scan results" @click="closeResults()" :disabled="busy">&times;</button>
                <div class="checker-processing" x-show="processing" x-cloak>
                    <aside class="checker-processing-score">
                        <h3 x-text="processing === 'fix' ? 'Your tailored CV' : 'Your Score'"></h3>
                        <div class="checker-processing-gauge" aria-hidden="true"><span></span></div>
                        <div class="checker-processing-placeholder" aria-hidden="true"></div>
                        <p class="checker-processing-score-note" x-text="processing === 'fix' ? 'Preparing your improved resume' : 'Your match score appears when the scan is complete'"></p>
                        <ul><template x-for="label in ['Content', 'Sections', 'ATS essentials', 'Tailoring']" :key="label"><li><span x-text="label"></span><i aria-hidden="true"></i></li></template></ul>
                    </aside>
                    <section class="checker-processing-main" aria-label="Processing progress">
                        <p class="checker-processing-eyebrow" x-text="processing === 'fix' ? 'FIX MY CV' : 'ATS SCAN'"></p>
                        <h2 x-text="processing === 'fix' ? 'Creating your tailored CV' : 'Scanning your resume'"></h2>
                        <ol><template x-for="(label, index) in progressSteps()" :key="label"><li :class="{ 'is-past': progress >= (index + 1) * 24, 'is-current': progress >= index * 24 && progress < (index + 1) * 24 }"><span class="checker-processing-check" aria-hidden="true" x-text="progress >= (index + 1) * 24 ? '✓' : index + 1"></span><span x-text="label"></span></li></template></ol>
                        <div class="checker-processing-meter" role="progressbar" aria-label="Estimated processing progress" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.floor(progress)"><span :style="{ width: progress + '%' }"></span></div>
                        <div class="checker-processing-caption"><span>Estimated progress</span><strong x-text="Math.floor(progress) + '%'"></strong></div>
                        <p class="checker-processing-note">Your CV is being reviewed. This can take a few minutes. Keep this page open; your results will appear automatically. You do not need to submit it again.</p>
                    </section>
                </div>
                <div x-show="!processing && !result && error" x-cloak>
                    <h2>We couldn't complete your scan</h2>
                    <p class="checker-error" x-text="error" role="alert"></p>
                    <button type="button" class="btn btn-primary" @click="scan()" :disabled="busy">Retry ATS scan</button>
                </div>
                <div x-show="!processing && result">
                <span class="checker-upload-icon"><x-icon name="scan-text" /></span>
                <h2 id="checker-result-title" x-text="busy ? 'Creating your tailored CV' : 'Your ATS scan result'"></h2>
                <x-score-pair :dynamic="true" />

                @include('workspace.partials.qualification-green-flags')
                <section class="checker-experience-warning" x-show="!busy && result?.experience_check?.required_years != null"><h3 x-text="result?.experience_check?.shortfall ? 'Experience gap to review' : 'Experience requirement'"></h3><p x-text="result?.experience_check?.message"></p><small x-text="result?.experience_check?.note"></small></section>
            @include('workspace.partials.checker-findings')
                <details x-show="!busy && result?.missing?.length" class="checker-score-explanation"><summary>Add skills you have that are missing from your CV</summary><p>Select only terms that accurately describe your skills. Revamp will include these naturally in Skills and rephrase supported experience using the job description.</p><div class="checker-confirmed-skills"><template x-for="word in (result?.confirmable_skills ?? result?.missing ?? []).slice(0, 200)" :key="word"><label><input type="checkbox" :value="word" x-model="confirmedKeywords" :disabled="busy"> <span x-text="word"></span></label></template></div></details>
                <div x-show="busy" role="status"><span class="checker-spinner" aria-hidden="true"></span><p>AI is tailoring your wording and arranging your new CV. Your review will open when it is ready.</p></div>
                <p class="checker-error" x-show="error" x-text="error" role="alert"></p>
                <button class="btn btn-primary" @click="fix()" :disabled="busy"><x-icon name="wand-sparkles" /><span x-text="busy ? 'Fixing resume...' : 'Fix Resume'"></span></button>
                <p class="checker-dialog-note" x-show="!busy">Create an ATS-friendly CV tailored to the advert using your existing experience.</p>
                </div>
            </div>
        </dialog>
        <form x-ref="generatedForm" method="POST" action="{{ route('checker.edit') }}" hidden>@csrf<input type="hidden" name="resume_text" :value="aiDraft?.rewritten_resume ?? ''"><input type="hidden" name="original_resume_text" :value="resumeText"><input type="hidden" name="job_description" :value="job"><input type="hidden" name="generated" value="1"><input type="hidden" name="structured_resume" :value="JSON.stringify(aiDraft?.structured_resume ?? null)"><template x-for="(change, index) in aiDraft?.changes ?? []" :key="index"><input type="hidden" name="ai_changes[]" :value="change"></template></form>
    </section>
</x-app-layout>

