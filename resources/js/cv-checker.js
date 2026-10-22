window.cvChecker = (boot) => ({
    step: 1, job: '', resumeText: '', filename: '', busy: false, error: '', result: null,
    modalOpen: false, aiDraft: null, originalScore: null, dragging: false,
    credits: boot.credits ?? 0, operations: {}, confirmedKeywords: [],
    matchLevel() {
        const score = this.result?.keyword_score ?? 0;
        return score >= 80 ? 'High requirements match' : score >= 50 ? 'Moderate requirements match' : 'Low requirements match';
    },
    operation(feature) {
        const fingerprint = JSON.stringify([this.job, this.resumeText, feature === 'revamp' ? this.confirmedKeywords : []]);
        if (this.operations[feature]?.fingerprint !== fingerprint) this.operations[feature] = {fingerprint, id: crypto.randomUUID()};
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
            throw new Error(message + (/^[0-9a-f-]{36}$/i.test(reference || '') ? ` (Reference: ${reference})` : ''));
        }
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('The server returned an unexpected response. Please reload and try again.');
        return body;
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
        this.busy = true; this.error = '';
        try {
            const result = await this.request(boot.scanUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ job_description: this.job, resume_text: text, operation_id: this.operation('analyse') }) });
            delete this.operations.analyse;
            this.resumeText = text; this.result = result; this.confirmedKeywords = [];
            if (this.originalScore === null) this.originalScore = result.score;
            this.step = 4;
            this.$nextTick(() => { this.$refs.scanDialog.showModal(); this.modalOpen = true; });
        } catch (error) { this.error = error.message; }
        finally { this.busy = false; }
    },
    closeResults() { if (!this.busy) this.$refs.scanDialog.close(); },
    async fix() {
        if (this.busy) return;
        if (!this.$refs.scanDialog.open) { this.$refs.scanDialog.showModal(); this.modalOpen = true; }
        this.busy = true; this.error = '';
        try {
            this.aiDraft = await this.request(boot.rewriteUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ job_description: this.job, resume_text: this.resumeText, confirmed_keywords: this.confirmedKeywords, operation_id: this.operation('revamp') }) });
            await this.$nextTick();
            this.$refs.generatedForm.requestSubmit();
        } catch (error) { this.error = error.message; this.busy = false; }
    },
});
