<x-app-layout>
    <section class="ats-checker" x-data="cvChecker({{ Js::from(['csrf' => csrf_token(), 'scanUrl' => route('checker.scan'), 'uploadUrl' => route('checker.upload'), 'rewriteUrl' => route('checker.rewrite'), 'credits' => app(\App\Services\AiCreditService::class)->wallet(auth()->user())['credits']]) }})">
        <div class="dashboard-heading"><div><h1>CV Checker</h1><p>Compare your CV with the requirements of a specific job.</p></div></div>
        <p class="checker-credit-balance"><strong x-text="credits"></strong> credits available &middot; Analysis: 5 credits &middot; Revamp: 10 credits. <a class="text-link" href="{{ route('subscription') }}#credit-topups">Buy more credits</a></p>
        <ol class="checker-steps" aria-label="Resume scan progress">
            <template x-for="(label, index) in ['Job description', 'Upload resume', 'ATS scan', 'View results']" :key="label">
                <li :class="{ active: step === index + 1, complete: step > index + 1 }" :aria-current="step === index + 1 ? 'step' : null"><span x-text="index + 1"></span><strong x-text="label"></strong></li>
            </template>
        </ol>
        <p x-show="error && !modalOpen" x-cloak x-text="error" class="checker-error" role="alert"></p>
        <section x-show="step === 1" class="checker-job-section">
            <h2>Start with the job description</h2><p>Paste the job description you want to compare your CV against.</p>
            <label for="checker-job">Job description</label><textarea id="checker-job" x-model="job" rows="3" maxlength="20000" placeholder="Paste the responsibilities, requirements and skills here..."></textarea>
            <div class="checker-actions"><button type="button" class="btn btn-primary" @click="nextJob()">Next: Upload resume <x-icon name="arrow-right" /></button></div>
        </section>
        <section x-show="step === 2" x-cloak>
            <div class="checker-drop" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; upload($event.dataTransfer.files[0])">
                <span class="checker-upload-icon"><x-icon name="upload" /></span><h2>Drag and drop a file here</h2><p>Files we can read: PDF, DOCX, TXT &middot; Up to 5 MB</p>
                <input type="file" x-ref="file" class="sr-only" aria-label="Resume file" accept=".pdf,.docx,.txt" @change="upload($event.target.files[0]); $event.target.value = ''" :disabled="busy">
                <button type="button" class="btn btn-primary" @click="$refs.file.click()" :disabled="busy" x-text="busy ? 'Reading resume...' : 'Upload Your Resume'"></button>
                <p x-show="filename" x-text="filename" class="checker-filename" role="status"></p>
            </div>
            <details class="checker-paste" :open="!!resumeText"><summary>Or paste resume text</summary><label for="checker-resume">Resume text &mdash; review or paste your CV</label><textarea id="checker-resume" x-model="resumeText" rows="12" maxlength="20000" placeholder="Paste your CV text here..."></textarea></details>
            <div class="checker-actions"><button class="btn btn-outline" @click="step = 1; error = ''" :disabled="busy">Back</button><button class="btn btn-primary" @click="nextResume()" :disabled="busy">Next: ATS scan <x-icon name="arrow-right" /></button></div>
        </section>
        <section x-show="step === 3" x-cloak class="checker-panel checker-scan">
            <span class="checker-upload-icon"><x-icon name="scan-text" /></span><h2>Compare your CV to the job</h2><p>We will find matching and missing keywords from your job description.</p>
            <div class="checker-actions"><button class="btn btn-outline" @click="step = 2" :disabled="busy">Back</button><button class="btn btn-primary" @click="scan()" :disabled="busy"><x-icon name="scan-text" /><span x-text="busy ? 'Scanning...' : 'ATS Scan'"></span></button></div>
        </section>
        <section x-show="step === 4 && result" x-cloak>
            <div class="checker-score-panel" aria-live="polite"><div class="checker-score" x-text="(result?.similarity_percent ?? result?.score ?? 0) + '%'"></div><div><h2>ATS text similarity</h2><p>Keyword coverage: <strong x-text="(result?.keyword_score ?? 0) + '%'"></strong>. <strong x-text="result?.matched.length"></strong> of <strong x-text="result?.total"></strong> job-description keywords found in your CV.</p><p class="checker-score-change" x-show="originalScore !== result?.score">Before: <span x-text="originalScore + '%'"></span> &rarr; After: <span x-text="result?.score + '%'"></span></p></div></div>
            <p class="checker-method">This score compares the wording in your CV with the job description. Keyword coverage shows how many job requirements appear in your CV. These are estimates, not an employer's hiring decision.</p>
            <p><a class="text-link" href="{{ route('workspace.module', 'history') }}">View saved checks</a></p>
            <div class="checker-keywords"><section class="checker-panel"><h2>Matched keywords</h2><p x-show="!result?.matched.length">No matching keywords yet.</p><div class="checker-tags"><template x-for="word in result?.matched ?? []" :key="word"><span x-text="word"></span></template></div></section><section class="checker-panel"><h2>Missing keywords</h2><p x-show="!result?.missing.length">All detected keywords are present.</p><div class="checker-tags missing"><template x-for="word in result?.missing ?? []" :key="word"><span x-text="word"></span></template></div></section></div>
            <section class="checker-panel checker-suggestions" x-show="result?.suggestions?.length"><h2>Suggestions for your CV</h2><ul><template x-for="item in result?.suggestions ?? []" :key="item.keyword"><li x-text="item.suggestion"></li></template></ul></section>
            <div class="checker-actions"><button class="btn btn-outline" @click="step = 1; error = ''" :disabled="busy">Change job description</button><button class="btn btn-primary" @click="fix()" :disabled="busy"><x-icon name="wand-sparkles" /><span x-text="busy ? 'Fixing resume...' : 'Fix Resume'"></span></button></div>
            <form method="POST" action="{{ route('checker.edit') }}" class="checker-export">@csrf<input type="hidden" name="resume_text" :value="resumeText"><button class="btn btn-outline" type="submit" :disabled="busy"><x-icon name="file-pen-line" />Review original CV</button></form>
        </section>
        <dialog x-ref="scanDialog" class="checker-result-dialog" aria-labelledby="checker-result-title" @cancel="if (busy) $event.preventDefault()" @close="modalOpen = false">
            <div class="checker-dialog-content" :aria-busy="busy">
                <button type="button" class="checker-dialog-close" aria-label="Close scan results" @click="closeResults()" :disabled="busy">&times;</button>
                <span class="checker-upload-icon"><x-icon name="scan-text" /></span>
                <h2 id="checker-result-title" x-text="busy ? 'Creating your tailored CV' : 'Your ATS scan result'"></h2>
                <div class="checker-dialog-score" x-text="(result?.similarity_percent ?? result?.score ?? 0) + '%'"></div>
                <p x-show="!busy">How closely your CV matches the wording in this job description.</p>
                <div x-show="!busy && result?.ai_analysis" class="checker-ai-recommendations"><p x-text="result?.ai_analysis?.summary"></p><ul><template x-for="(tip,index) in result?.ai_analysis?.recommendations ?? []" :key="index"><li x-text="tip"></li></template></ul></div>
                <div x-show="busy" role="status"><span class="checker-spinner" aria-hidden="true"></span><p>AI is tailoring your wording and arranging your new CV. Your review will open when it is ready.</p></div>
                <p class="checker-error" x-show="error" x-text="error" role="alert"></p>
                <button class="btn btn-primary" @click="fix()" :disabled="busy"><x-icon name="wand-sparkles" /><span x-text="busy ? 'Fixing resume...' : 'Fix Resume'"></span></button>
                <p class="checker-dialog-note" x-show="!busy">Create an ATS-friendly CV tailored to the advert using your existing experience.</p>
            </div>
        </dialog>
        <form x-ref="generatedForm" method="POST" action="{{ route('checker.edit') }}" hidden>@csrf<input type="hidden" name="resume_text" :value="aiDraft?.rewritten_resume ?? ''"><input type="hidden" name="original_resume_text" :value="resumeText"><input type="hidden" name="job_description" :value="job"><input type="hidden" name="generated" value="1"><input type="hidden" name="structured_resume" :value="JSON.stringify(aiDraft?.structured_resume ?? null)"><template x-for="(change, index) in aiDraft?.changes ?? []" :key="index"><input type="hidden" name="ai_changes[]" :value="change"></template></form>
    </section>
</x-app-layout>

