window.cvChecker = (boot) => ({
    step: 1, job: '', resumeText: '', filename: '', busy: false, error: '', result: null,
    modalOpen: false, aiDraft: null, originalScore: null, dragging: false,
    credits: boot.credits ?? 0, operations: {}, confirmedKeywords: [],
    processing: '', progress: 0, progressTimer: null, pendingUrl: null, pendingFeature: null, pollDelay: 2500,
    progressSteps() {
        return this.processing === 'fix'
            ? ['Reviewing your CV and job requirements', 'Tailoring your experience', 'Highlighting skills and competencies', 'Preparing your fixed CV']
            : ['Parsing your resume', 'Analyzing your experience', 'Extracting your skills', 'Generating recommendations'];
    },
    startProgress(mode) {
        this.stopProgress();
        this.processing = mode; this.progress = 0;
        if (!this.$refs.scanDialog.open) this.$refs.scanDialog.showModal();
        this.modalOpen = true;
        this.progressTimer = setInterval(() => {
            // Estimated waiting progress: only a successful API response completes it.
            this.progress = Math.min(94, this.progress + Math.max(0.2, (94 - this.progress) * 0.045));
        }, 700);
    },
    stopProgress() { clearInterval(this.progressTimer); this.progressTimer = null; },
    destroy() { this.stopProgress(); },
    requirementGroups(found) {
        const groups = this.result?.assessment?.job_requirements ?? this.result?.requirement_groups ?? {};
        return Object.entries(groups).map(([category, entries]) => [category, entries.filter(item => {
            const matched = item.matched ?? ['matched', 'demonstrated', 'directly_evidenced'].includes(item.original_status);
            return matched === found;
        })]).filter(([, entries]) => entries.length > 0);
    },
    matchLevel() {
        const score = this.result?.keyword_score ?? 0;
        return score >= 80 ? 'High requirements match' : score >= 50 ? 'Moderate requirements match' : 'Low requirements match';
    },
    operation(feature) {
        const fingerprint = JSON.stringify([this.job, this.resumeText, feature === 'revamp' ? this.confirmedKeywords : []]);
        if (this.operations[feature]?.fingerprint !== fingerprint) {
            // randomUUID requires HTTPS; getRandomValues also works on local-network HTTP previews.
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            const id = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
            this.operations[feature] = {fingerprint, id};
        }
        return this.operations[feature].id;
    },
    nextJob() {
        if (!this.job.trim()) { this.error = 'Paste the job description first.'; return; }
        this.error = ''; this.result = null; this.originalScore = null; this.modalOpen = false; this.confirmedKeywords = []; this.step = 2;
    },
    nextResume() {
        if (!this.resumeText.trim()) { this.error = 'Upload your resume or paste its text first.'; return; }
        this.error = ''; this.step = 3;
    },
    async request(url, options) {
        const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf, ...(options.headers || {}) } });
        const body = await response.json().catch(() => ({}));
        if (Number.isInteger(body.credits_remaining)) {
            this.credits = body.credits_remaining;
            window.dispatchEvent(new CustomEvent('credits-updated'));
        }
        if (response.status === 401 || response.status === 419) throw new Error('Your session has expired. Please sign in again, then retry.');
        if (response.status === 429) throw new Error('Too many requests. Please wait a moment before trying again.');
        if (!response.ok) {
            if (response.status === 503) this.operations = {};
            const message = Object.values(body.errors || {}).flat()[0] || body.message || 'Something went wrong. Please try again.';
            const reference = body.reference || response.headers.get('X-Error-Reference');
            const error = new Error(message + (/^[0-9a-f-]{36}$/i.test(reference || '') ? ` (Reference: ${reference})` : ''));
            error.terminal = body.status === 'failed';
            throw error;
        }
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('The server returned an unexpected response. Please reload and try again.');
        return body;
    },
    async aiRequest(feature, url, options) {
        // Keep the status URL after network errors so Retry resumes the existing job.
        const fingerprint = JSON.stringify([this.job, this.resumeText, feature === 'revamp' ? this.confirmedKeywords : []]);
        if (this.pendingUrl && (this.pendingFeature !== feature || this.pendingFingerprint !== fingerprint)) {
            throw new Error('Your previous request is still running. Restore its inputs and retry to view the result before starting another request.');
        }
        let result;
        if (this.pendingFeature === feature && this.pendingUrl) {
            result = { status: 'processing' };
        } else {
            result = await this.request(url, options);
            if (result.status === 'processing') {
                this.pendingUrl = result.status_url;
                this.pendingFeature = feature;
                this.pendingFingerprint = fingerprint;
            }
        }
        while (result.status === 'processing') {
            await new Promise(resolve => setTimeout(resolve, this.pollDelay));
            try {
                result = await this.request(this.pendingUrl, { method: 'GET' });
            } catch (error) {
                if (error.terminal) { this.pendingUrl = null; this.pendingFeature = null; }
                throw error;
            }
        }
        this.pendingUrl = null; this.pendingFeature = null;
        return result;
    },
    async upload(file) {
        if (!file || this.busy) return;
        if (!/\.(pdf|docx|txt)$/i.test(file.name) || file.size > 5 * 1024 * 1024) { this.error = 'Choose a PDF, DOCX or TXT file up to 5 MB.'; return; }
        this.busy = true; this.error = '';
        try {
            const form = new FormData(); form.append('file', file);
            const data = await this.request(boot.uploadUrl, { method: 'POST', body: form });
            this.resumeText = data.text; this.filename = file.name; this.result = null; this.confirmedKeywords = [];
        } catch (error) { this.error = error.message; }
        finally { this.busy = false; }
    },
    async detect() {
        if (this.busy) return;
        if (!this.resumeText.trim()) { this.error = 'Upload your CV or paste its text first.'; return; }
        this.busy = true; this.error = ''; this.result = null;
        try {
            this.result = await this.request(boot.detectUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ resume_text: this.resumeText }) });
            this.$nextTick(() => this.$refs.detectResults.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        } catch (error) { this.error = error.message; }
        finally { this.busy = false; }
    },
    async scan() {
        if (this.busy) return;
        const text = this.resumeText;
        if (!text.trim()) { this.error = 'Your resume cannot be empty.'; return; }
        this.result = null; this.step = 3;
        this.busy = true; this.error = '';
        this.startProgress('scan');
        try {
            const result = await this.aiRequest('analyse', boot.scanUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ job_description: this.job, resume_text: text, operation_id: this.operation('analyse') }) });
            delete this.operations.analyse;
            this.progress = 100;
            this.resumeText = text; this.result = result; this.confirmedKeywords = [];
            if (this.originalScore === null) this.originalScore = result.keyword_score;
            this.step = 4;
            this.$nextTick(() => { this.$refs.scanDialog.showModal(); this.modalOpen = true; });
        } catch (error) { this.error = error.message; }
        finally { this.stopProgress(); this.processing = ''; this.busy = false; }
    },
    closeResults() { if (!this.busy) this.$refs.scanDialog.close(); },
    async fix() {
        if (this.busy) return;
        if (!this.$refs.scanDialog.open) { this.$refs.scanDialog.showModal(); this.modalOpen = true; }
        this.busy = true; this.error = '';
        this.startProgress('fix');
        try {
            this.aiDraft = await this.aiRequest('revamp', boot.rewriteUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ job_description: this.job, resume_text: this.resumeText, confirmed_keywords: this.confirmedKeywords, operation_id: this.operation('revamp') }) });
            this.stopProgress(); this.progress = 100;
            await this.$nextTick();
            this.$refs.generatedForm.requestSubmit();
        } catch (error) { this.stopProgress(); this.processing = ''; this.error = error.message; this.busy = false; }
    },
});
