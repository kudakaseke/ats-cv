<x-app-layout>
    <section class="ats-checker" x-data="cvChecker({{ Js::from(['csrf' => csrf_token(), 'detectUrl' => route('checker.detect'), 'uploadUrl' => route('checker.upload')]) }})">
        <div class="dashboard-heading"><div><h1>ATS Checker</h1><p>Check your CV’s basic ATS readiness. No job description needed.</p></div></div>
        <p>Free text and structure check. For a job-specific comparison, <a class="text-link" href="{{ route('workspace.module', 'checker') }}">use Job-to-CV ATS Detector</a>.</p>
        <section class="checker-panel">
            <div class="checker-drop" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; upload($event.dataTransfer.files[0])">
                <span class="checker-upload-icon"><x-icon name="upload" /></span><h2>Upload your CV</h2><p>PDF, DOCX or TXT · Up to 5 MB</p>
                <label class="btn btn-outline" for="detect-file">Choose CV</label><input id="detect-file" type="file" accept=".pdf,.docx,.txt" class="sr-only" :disabled="busy" @change="upload($event.target.files[0]); $event.target.value = ''">
                <p x-show="filename" x-text="filename" role="status"></p>
            </div>
            <details class="checker-paste" :open="!!resumeText"><summary>Or paste CV text</summary><label for="detect-resume">CV text</label><textarea id="detect-resume" x-model="resumeText" @input="result = null" :disabled="busy" rows="12" maxlength="20000" placeholder="Paste your CV here..."></textarea></details>
            <p x-show="error" x-text="error" class="checker-error" role="alert"></p>
            <div class="checker-actions"><button class="btn btn-primary" type="button" @click="detect()" :disabled="busy"><x-icon name="scan-text" /><span x-text="busy ? 'Reading your CV...' : 'Check ATS readiness'"></span></button></div>
        </section>
        <section x-ref="detectResults" x-show="result" x-cloak class="checker-panel" aria-live="polite">
            <h2 x-text="result?.summary"></h2><p><strong x-text="result?.passed"></strong> of <strong x-text="result?.total"></strong> basic checks passed.</p>
            <ul><template x-for="check in result?.checks ?? []" :key="check.label"><li><strong x-text="(check.passed ? 'Passed: ' : 'Review: ') + check.label"></strong><p x-show="!check.passed" x-text="check.advice"></p></li></template></ul>
            <p class="checker-method" x-text="result?.scope"></p>
            <div class="checker-actions"><a class="btn btn-primary" href="{{ route('workspace.module', 'checker') }}">Compare with a job description</a><a class="btn btn-outline" href="{{ route('builder.start', ['new' => 1]) }}">Create CV</a></div>
        </section>
    </section>
</x-app-layout>
