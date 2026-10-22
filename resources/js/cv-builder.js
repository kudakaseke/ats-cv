import Alpine from 'alpinejs';

const blankExperience = () => ({ title: '', company: '', period: '', location: '', description: '' });
const blankEducation = () => ({ degree: '', school: '', period: '', location: '', grade: '' });
const defaults = () => ({ section_titles: {}, hidden_sections: [], section_positions: [], template: 'double-column', role: '', name: '', email: '', phone: '', location: '', website: '', summary: '', skills: '', experience: [blankExperience()], education: [blankEducation()], achievements: [{ title: '', description: '' }], courses: [{ title: '', institution: '' }], languages: [{ name: '', level: 'Fluent' }], competencies: '', core_strengths: '', references: '', custom_sections: [], imported_text: '' });
const sample = { ...defaults(), name: 'John Doe', role: 'Your next career opportunity', email: 'john.doe@example.com', phone: '+27 71 234 5678', location: 'Johannesburg, South Africa', website: 'linkedin.com/in/johndoe', summary: 'A thoughtful professional who turns complex challenges into practical results. Experienced in building strong relationships, improving processes, and delivering work that makes a difference.', skills: 'Project planning, Communication, Leadership, Problem solving, Research, Collaboration', experience: [{ title: 'Senior Specialist', company: 'Northstar Group', period: '2022 – Present', location: 'Johannesburg', description: 'Led cross-functional projects from idea to delivery.\nImproved team workflows and introduced a clear approach to reporting.\nBuilt lasting relationships with clients and colleagues.' }, { title: 'Specialist', company: 'Bright Futures', period: '2019 – 2022', location: 'Cape Town', description: 'Supported a growing team across daily operations and strategic initiatives.\nDeveloped practical solutions through research and collaboration.' }, { title: 'Associate', company: 'Common Ground', period: '2017 – 2019', location: 'Pretoria', description: 'Coordinated projects and maintained accurate documentation.\nWorked closely with colleagues to deliver excellent service.' }], education: [{ degree: 'Bachelor of Commerce', school: 'University of Johannesburg', period: '2013 – 2016', location: 'Johannesburg' }], achievements: [{ title: 'Making an impact', description: 'Delivered a successful initiative through careful planning and teamwork.' }, { title: 'Recognised for excellence', description: 'Received recognition for consistent work and attention to detail.' }], courses: [{ title: 'Professional Development', institution: 'Continuing Education Institute' }], languages: [{ name: 'English', level: 'Fluent' }, { name: 'Shona', level: 'Native' }] };
// These examples belong only to the gallery, never to an applicant's draft.
for (const entry of sample.experience) {
    entry.description += '\nPrepared clear progress updates, recorded outstanding actions and followed up with colleagues to keep delivery on track.\nReviewed supporting information for accuracy and helped the team resolve recurring issues before deadlines.';
}
sample.summary += ' Combines careful planning with clear communication and consistent follow-through. Works collaboratively to identify practical improvements and maintain dependable service.';
sample.courses.push({title: 'Communication and Team Development', institution: 'Professional Learning Centre', period: '2021'});
const escape = (text) => String(text ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

const normaliseSections = data => {
    const strengths = [...new Map([data.core_strengths, data.competencies].flatMap(value => String(value || '').split(/[,;\n|]+/))
        .map(value => value.trim()).filter(Boolean).map(value => [value.toLocaleLowerCase(), value])).values()];
    const section_titles = {...(data.section_titles || {})};
    if (/^strengths\s*(?:and|&|\/)\s*competenc(?:ies|es)$/i.test(section_titles.core_strengths || '')) section_titles.core_strengths = 'Competencies';
    return {...data, section_titles, core_strengths: strengths.join(', '), competencies: ''};
};

Alpine.data('cvBuilder', (boot) => ({
    step: boot.draft.step || 1, data: normaliseSections({ ...defaults(), ...(boot.draft.data || {}) }), templates: boot.templates, allowedTemplateIds: boot.allowedTemplateIds || boot.templates.map(template => template.id),
    inlineSections: [], addSectionId: 'achievements',
    reviewZoom: 100, reviewing: Boolean(boot.savedAccount || boot.reviewRequested), editorExpanded: false, downloadMenu: false, downloading: '',
    departments: boot.departments || [], departmentRoles: {},
    positionFeatured: boot.positionFeatured || [], positionGroups: boot.positionGroups || [], positionSearch: '', positionIndustry: 'All', positionLimit: 12,
    category: 'All', tab: 'personal', importing: false, importError: '', saveState: 'Your draft stays in this browser session', busy: false, error: '', successMessage: '', successTimer: null, timer: null, saveQueue: Promise.resolve(),
    init() {
        // Laravel stores empty optional fields as null; restore editable string values.
        for (const [key, fallback] of Object.entries(defaults())) {
            if (this.data[key] == null) this.data[key] = fallback;
        }
        this.$watch('data', () => { clearTimeout(this.timer); this.saveState = 'Unsaved changes'; this.timer = setTimeout(() => this.save(), 900); });
        this._fitFrame = null;
        this._fitCache = new Map();
        this._fitResize = new ResizeObserver(() => this.schedulePageFit());
        this._fitMutations = new MutationObserver(() => {
            this._fitResize.disconnect();
            this.$root.querySelectorAll('.cv-stage').forEach(stage => this._fitResize.observe(stage));
            this.schedulePageFit();
        });
        this.$root.querySelectorAll('.cv-stage').forEach(stage => this._fitResize.observe(stage));
        this._fitMutations.observe(this.$root, { childList: true, subtree: true, characterData: true });
        this._beforePrint = () => this.fitPrintedPage();
        this._afterPrint = () => {
            this.$root.classList.remove('cv-print-fit');
            this.schedulePageFit();
        };
        window.addEventListener('beforeprint', this._beforePrint);
        window.addEventListener('afterprint', this._afterPrint);
        this._beforeUnload = event => {
            if (['Unsaved changes', 'Saving…', 'Could not save'].includes(this.saveState)) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this._beforeUnload);
        document.fonts.ready.then(() => { this._fitCache.clear(); this.schedulePageFit(); });
        this.$nextTick(() => this.schedulePageFit());

    },
    destroy() {
        clearTimeout(this.successTimer);
        clearTimeout(this.timer);
        cancelAnimationFrame(this._fitFrame);
        this._fitCache?.clear();
        this._fitResize?.disconnect();
        this._fitMutations?.disconnect();
        window.removeEventListener('beforeprint', this._beforePrint);
        window.removeEventListener('afterprint', this._afterPrint);
        window.removeEventListener('beforeunload', this._beforeUnload);
    },
    pageDimensions(templateId = this.data.template) {
        const word = templateId === 'ats-professional' || this.templates.find(template => template.id === templateId)?.category === 'By Department';
        const width = word ? 215.9 : 210;
        const height = word ? 279.4 : 297;
        const designWidth = word ? 816 : 794;
        return { width, height, designWidth, designHeight: designWidth * height / width };
    },
    schedulePageFit() {
        cancelAnimationFrame(this._fitFrame);
        this._fitFrame = requestAnimationFrame(() => {
            if (this.$root.classList.contains('cv-print-fit')) return;
            this.$root.querySelectorAll('.cv-stage').forEach(stage => this.fitStage(stage));
        });
    },
    fitStage(stage) {
        const paper = stage.querySelector('.cv-paper');
        const width = stage.getBoundingClientRect().width;
        if (!paper || !width) return;
        const page = this.pageDimensions(paper.dataset.template);
        const pages = stage.querySelector('.cv-document-pages');
        if (pages) {
            const printing = this.$root.classList.contains('cv-print-fit');
            const scale = printing ? 1 : width / page.designWidth;
            stage.style.setProperty('--cv-display-scale', scale);
            stage.style.setProperty('--cv-fit-scale', 1);
            stage.style.setProperty('--cv-design-width', `${page.designWidth}px`);
            stage.style.height = printing ? 'auto' : `${pages.offsetHeight * scale}px`;
            return;
        }
        if (stage.classList.contains('editor-paper-wrap') && !this.reviewing && !this.$root.classList.contains('cv-print-fit')) {
            const displayScale = width / page.designWidth;
            stage.style.setProperty('--cv-design-width', `${page.designWidth}px`);
            stage.style.setProperty('--cv-display-scale', displayScale);
            stage.style.setProperty('--cv-fit-scale', 1);
            stage.style.setProperty('--cv-page-height', `${page.designHeight}px`);
            stage.style.height = `${Math.max(page.designHeight, paper.scrollHeight, paper.offsetHeight) * displayScale}px`;
            stage.style.setProperty('--cv-stage-height', stage.style.height);
            return;
        }
        const displayScale = width / page.designWidth;
        stage.style.height = `${page.designHeight * displayScale}px`;
        stage.style.setProperty('--cv-design-width', `${page.designWidth}px`);
        stage.style.setProperty('--cv-display-scale', displayScale);
        this._fitCache ??= new Map();
        const key = `${paper.dataset.template}:${paper.innerHTML}`;
        if (this._fitCache.has(key)) {
            stage.style.setProperty('--cv-fit-scale', this._fitCache.get(key));
        } else {
            this.fitPaper(stage, paper, page.designHeight - 4);
            this._fitCache.set(key, stage.style.getPropertyValue('--cv-fit-scale'));
            if (this._fitCache.size > 64) this._fitCache.delete(this._fitCache.keys().next().value);
        }
    },
    fitPaper(wrap, paper, height) {
        const fits = scale => {
            wrap.style.setProperty('--cv-fit-scale', scale);
            return Math.max(paper.offsetHeight, paper.scrollHeight) * scale <= height;
        };
        if (fits(1)) return;
        // Reflow into the full page width while finding the largest fitting size.
        let low = 0.001;
        let high = 1;
        for (let i = 0; i < 14; i++) {
            const scale = (low + high) / 2;
            if (fits(scale)) low = scale;
            else high = scale;
        }
        wrap.style.setProperty('--cv-fit-scale', low);
    },
    fitPrintedPage() {
        const wrap = this.$root.querySelector('.editor-paper-wrap');
        const paper = wrap?.querySelector('.cv-paper');
        if (!paper) return;
        const page = this.pageDimensions();
        this.$root.style.setProperty('--cv-print-width', `${page.width}mm`);
        this.$root.style.setProperty('--cv-print-height', `${page.height - 0.5}mm`);
        this.$root.classList.add('cv-print-fit');
        this.fitStage(wrap);
    },
    setReviewZoom(value) {
        const zoom = Number(value);
        if (!Number.isFinite(zoom)) return;
        this.reviewZoom = Math.max(25, Math.min(200, Math.round(zoom)));
        this.$nextTick(() => this.schedulePageFit());
    },
    startReview() {
        if (!this.data.name.trim()) {
            this.reviewing = false; this.editorExpanded = false; this.tab = 'personal';
            this.error = 'Please enter your name before reviewing your CV.';
            return false;
        }
        this.error = ''; this.downloadMenu = false; this.reviewing = true; this.editorExpanded = false;
        this.$nextTick(() => this.schedulePageFit());
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return true;
    },
    editCv() {
        if (this.downloading) return;
        this.reviewing = false; this.downloadMenu = false; this.editorExpanded = false;
    },
    editSection(section) {
        if (this.downloading) return;
        this.tab = section;
        if (this.reviewing) this.editCv();
        this.$nextTick(() => {
            if (this.editorExpanded) this.$root.querySelector('.editor-fields:not([style*="display: none"]) input, .editor-fields:not([style*="display: none"]) textarea')?.focus();
        });
    },
    openDownloads() {
        if (!this.reviewing) { this.startReview(); return; }
        this.downloadMenu = !this.downloadMenu;
        if (this.downloadMenu) this.$nextTick(() => this.$refs.pdfDownload.focus());
    },
    async download(format) {
        if (!['pdf', 'word'].includes(format) || this.downloading) return;
        if (!this.reviewing || !this.data.name.trim()) { this.startReview(); return; }
        if (!boot.authenticated) { if (await this.save()) window.location.assign(boot.continueUrl); return; }
        this.downloading = format; this.downloadMenu = false; this.error = '';
        try {
            const response = await fetch(boot.exportUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf }, body: JSON.stringify({ step: 4, data: this.data, format }) });
            if (!response.ok) await this.responseData(response, 'Your download could not be prepared. Please try again.');
            const expected = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            if (!response.headers.get('Content-Type')?.includes(expected)) throw new Error('Your download could not be prepared. Please try again.');
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${this.data.name.replace(/[^\p{L}\p{N} _-]/gu, '').trim().slice(0, 80) || 'My'} CV.${format === 'pdf' ? 'pdf' : 'docx'}`;
            document.body.appendChild(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            this.showSuccess(`Your ${format === 'pdf' ? 'PDF' : 'Word document'} is ready to download.`);
        } catch (error) { this.error = error.message; }
        finally { this.downloading = ''; }
    },
    get selected() { return this.templates.find(t => t.id === this.data.template) || this.templates[0]; },
    get recommendedIds() {
        if (!this.data.role.trim()) return [];
        if (/law|account|financ|legal|bank/i.test(this.data.role)) return ['classic', 'executive', 'heritage'];
        if (/design|creativ|market|art|content/i.test(this.data.role)) return ['studio', 'editorial', 'portfolio'];
        if (/student|graduate|intern|entry/i.test(this.data.role)) return ['graduate', 'minimal', 'essential'];
        return ['double-column', 'elegant', 'precision'];
    },
    templateCategories: ['Modern', 'Simple', 'Traditional', 'Creative', 'ATS Pass'],
    selectCategory(category) {
        this.category = category;
        this.$nextTick(() => {
            const filters = this.$refs.templateFilters;
            if (!filters) return;
            window.scrollTo({
                top: Math.max(0, filters.getBoundingClientRect().top + window.scrollY - 16),
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
            });
        });
    },
    async goToPositionTemplates() {
        this.category = 'By Department';
        const role = this.data.role.trim();
        const department = this.departmentExamples.find(item => item.roles.some(title => title.toLowerCase() === role.toLowerCase()));
        if (department) this.departmentRoles[department.id] = department.roles.find(title => title.toLowerCase() === role.toLowerCase());
        await this.go(3);
    },
    get departmentExamples() {
        return this.departments.map(department => ({...department, template: this.templates.find(template => template.id === department.template)})).filter(department => department.template);
    },
    departmentPreview(department) {
        // One representative role per department; selecting a different role
        // changes the user's headline, never invents role-specific credentials.
        return this.preview(department.template, false, this.departmentExample(department.template, department.roles[0]));
    },
    departmentExample(template, role = '') {
        const p = template.department_profile;
        const department = this.departments.find(item => item.template === template.id);
        return {...defaults(), name: 'John Doe', role: role || department?.roles[0] || template.name,
            email: 'john.doe@example.com', phone: '+27 71 234 5678', location: 'Johannesburg, South Africa',
            summary: `Professional with experience in ${p.focus}. Organised, collaborative and committed to accurate, dependable work. Combines practical knowledge with clear communication, careful planning and consistent follow-through. Works with colleagues to resolve outstanding issues, maintain reliable records and improve the quality of everyday service.`,
            skills: p.skill_sample,
            experience: [{title: role || department?.roles[0] || template.name, company: 'Example Organisation', period: 'Jan 2022 - Present', location: 'Johannesburg', description: p.work + '\nPrepared clear progress updates, documented outstanding actions and followed up with the responsible colleagues.\nReviewed completed work for accuracy and helped the team introduce a consistent approach to recurring tasks.'},
                {title: 'Assistant — ' + template.name, company: 'Example Services', period: 'Jan 2020 - Dec 2021', location: 'Pretoria', description: `Supported the team with ${p.focus}.\nMaintained accurate records and communicated outstanding actions to colleagues.\nOrganised supporting documents, checked information for completeness and resolved routine enquiries.\nWorked with colleagues to meet deadlines and improve the reliability of departmental records.`},
                {title: 'Trainee — ' + template.name, company: 'Example Training Organisation', period: 'Jan 2018 - Dec 2019', location: 'Pretoria', description: `Gained supervised practical experience in ${p.focus}.\nAssisted with routine departmental tasks and maintained an organised record of completed work.\nApplied feedback from supervisors to improve accuracy, communication and professional practice.`}],
            education: [{degree: p.degree, school: 'Example College', period: 'Jan 2017 - Dec 2019', location: 'South Africa'}],
            courses: [{title: p.training, institution: 'Example Training Provider', period: 'Jun 2023'}],
            achievements: [{title: p.outcome, description: 'Introduced a consistent checklist and clearer records to support reliable team delivery. Helped colleagues identify missing information earlier and keep outstanding actions visible.'}],
            core_strengths: 'Attention to detail, Organisation, Communication, Teamwork, Problem solving, Accountability, Adaptability, Time management, Professionalism, Reliability',
            languages: [{name: 'English', level: 'Fluent'}], custom_sections: p.project ? [{title: p.project, content: p.project_sample}] : [],
        };
    },
    async chooseDepartment(department) {
        if (!this.allowedTemplateIds.includes(department.template.id)) { await this.choose(department.template); return; }
        const role = this.departmentRoles[department.id];
        if (role) this.data.role = role;
        await this.choose(department.template);
    },
    get positionExamples() {
        const featured = this.positionFeatured.map(position => ({...position, template: this.templates.find(template => template.id === position.template), featured: true})).filter(position => position.template);
        const featuredTitles = new Set(featured.flatMap(position => [position.title, ...(position.aliases || [])]).map(title => title.toLowerCase()));
        const remaining = this.positionGroups.flatMap((group, groupIndex) => group.roles.map((title, index) => ({
            id: `position-${groupIndex}-${index}`, title, industry: group.industry,
            template: this.templates.find(template => template.id === group.template), group,
        }))).filter(position => position.template && !featuredTitles.has(position.title.toLowerCase()));
        return [...featured, ...remaining];
    },
    get filteredPositions() {
        const words = this.positionSearch.toLowerCase().trim().split(/\s+/).filter(Boolean);
        return this.positionExamples.filter(position => (this.positionIndustry === 'All' || position.industry === this.positionIndustry)
            && words.every(word => `${position.title} ${position.industry} ${(position.aliases || []).join(' ')} ${position.example?.role || ''}`.toLowerCase().includes(word)));
    },
    positionPreview(position) {
        if (position.example) return this.preview(position.template, false, {...defaults(), ...position.example});
        const group = position.group;
        const names = ['Amira Patel', 'Noah Dlamini', 'Leah Jacobs', 'Daniel Mokoena', 'Zoe Williams', 'Ethan Naidoo', 'Maya Daniels', 'Luca Adams', 'Aisha Khan', 'Theo Nkosi', 'Sofia Botha', 'Caleb Smith', 'Nadia Pillay', 'Owen Meyer', 'Emma Maseko', 'Isaac van Wyk', 'Chloe Peters', 'Liam Ndlovu'];
        const person = names[Math.max(0, this.positionGroups.findIndex(item => item.industry === position.industry)) % names.length];
        const example = {
            ...defaults(), name: person, role: position.title,
            email: `${person.toLowerCase().replace(/\s+/g, '.')}@example.com`, phone: '+27 71 234 5678', location: 'Cape Town, South Africa', website: 'linkedin.com/in/example-profile',
            summary: `A dependable ${position.title.toLowerCase()} who brings a clear, organised approach to daily work. Combines practical knowledge with careful communication and a focus on useful outcomes.`,
            skills: group.skills,
            experience: [
                {title: position.title, company: 'Example Organisation', period: 'Mar 2022 - Present', location: 'Cape Town', description: group.bullets.join('\n')},
                {title: 'Team Associate', company: 'Example Services', period: 'Jan 2020 - Feb 2022', location: 'Johannesburg', description: 'Supported the team with accurate records and dependable follow-through.\nDocumented completed work and shared progress at regular team reviews.'},
            ],
            education: [{degree: `Qualification in ${group.education}`, school: 'Example Learning Institute', period: '2017 - 2019', location: 'South Africa'}],
            courses: [{title: `${group.education} professional development`, institution: 'Example Training Centre'}],
            achievements: [{title: 'Team contribution', description: 'Recognised for reliable delivery and constructive collaboration.'}],
            languages: [{name: 'English', level: 'Fluent'}],
        };
        return this.preview(position.template, false, example);
    },
    async choosePosition(position) {
        if (!this.allowedTemplateIds.includes(position.template.id)) { await this.choose(position.template); return; }
        this.data.role = position.title;
        await this.choose(position.template);
    },
    get templateGroups() {
        const seen = new Set();
        return this.templateCategories.map(category => ({
            category,
            templates: this.templates.filter(template => {
                if (template.category !== category || seen.has(template.id)) return false;
                seen.add(template.id);
                return true;
            }).sort((a, b) => Number(this.recommendedIds.includes(b.id)) - Number(this.recommendedIds.includes(a.id))),
        })).filter(group => group.templates.length && (this.category === 'All' || group.category === this.category));
    },
    get sections() { return [{ id: 'personal', label: 'Personal details' }, { id: 'summary', label: 'Summary' }, { id: 'experience', label: 'Experience' }, { id: 'education', label: 'Education' }, { id: 'skills', label: 'Skills' }, { id: 'achievements', label: 'Achievements' }, { id: 'courses', label: 'Courses' }, { id: 'languages', label: 'Languages' }, { id: 'core_strengths', label: 'Competencies' }, { id: 'references', label: 'References' }, { id: 'custom_sections', label: 'Additional sections' }]; },
    async builderBack() {
        if (this.busy || this.downloading || this.importing) return;
        await this.leave(boot.dashboardUrl || '/dashboard');
    },
    async go(step) { this.downloadMenu = false; if (step !== 4) this.reviewing = false; this.step = step; this.error = ''; await this.save(); window.scrollTo({ top: 0, behavior: 'smooth' }); },
    async choose(template) {
        if (!this.allowedTemplateIds.includes(template.id)) {
            await this.leave(boot.pricingUrl || '/pricing');
            return;
        }
        this.data.template = template.id;
        this.reviewing = false;
        await this.go(4);
        if (this.data.imported_text && !boot.authenticated && await this.save()) window.location.assign(boot.continueUrl);
    },
    async responseData(response, fallback) {
        let result = {};
        let parsed = false;
        try { result = await response.json(); parsed = true; } catch { /* Some error responses contain HTML. */ }
        if (response.status === 401 || response.status === 419) throw new Error('Your session has expired. Reload the page before saving or importing again.');
        if (response.status === 429) {
            const seconds = Number(response.headers.get('Retry-After'));
            throw new Error(seconds > 0 ? `Too many requests. Wait ${seconds} seconds and try again.` : 'Too many requests. Please wait and try again.');
        }
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || fallback);
        if (!parsed) throw new Error(fallback);
        return result;
    },
    async save() {
        clearTimeout(this.timer);
        const payload = JSON.stringify({ step: this.step, data: this.data, resume_id: boot.resumeId ?? null });
        const task = this.saveQueue.catch(() => {}).then(async () => {
            this.saveState = 'Saving…';
            try {
                const response = await fetch(boot.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf }, body: payload });
                await this.responseData(response, 'Unable to save. Please try again.');
                const current = payload === JSON.stringify({ step: this.step, data: this.data, resume_id: boot.resumeId ?? null });
                this.saveState = current ? (boot.savedAccount ? 'Saved to your account' : 'Draft saved for this session') : 'Unsaved changes';
                if (current) this.error = '';
                return true;
            } catch (error) {
                if (payload === JSON.stringify({ step: this.step, data: this.data, resume_id: boot.resumeId ?? null })) { this.saveState = 'Could not save'; this.error = error.message; }
                return false;
            }
        });
        this.saveQueue = task;
        return task;
    },
    async finish() {
        if (this.busy) return;
        if (!this.data.name.trim()) { this.tab = 'personal'; this.error = 'Please enter your name before continuing.'; return; }
        this.busy = true;
        if (await this.save()) window.location.assign(boot.finishUrl);
        else this.busy = false;
    },
    async leave(url) {
        if (this.busy || this.downloading) return;
        this.busy = true;
        if (await this.save()) window.location.assign(url);
        else this.busy = false;
    },
    async upload(event) {
        const file = event.target.files?.[0]; if (!file) return;
        if (file.size > 2 * 1024 * 1024) { this.importError = 'Please choose a file no larger than 2 MB.'; event.target.value = ''; return; }
        this.importing = true; this.importError = '';
        const body = new FormData(); body.append('file', file);
        try {
            const response = await fetch(boot.importUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf }, body });
            const result = await this.responseData(response, 'The file could not be imported. Please try again.');
            this.data.imported_text = result.text;
            for (const [key, value] of Object.entries(result.data || { email: result.email })) {
                const existing = this.data[key];
                const empty = Array.isArray(existing) ? !existing.some(row => Object.entries(row).some(([field, value]) => field !== 'level' && String(value ?? '').trim())) : !String(existing ?? '').trim();
                if (key === 'custom_sections' && Array.isArray(value)) {
                    const additions = value.filter(row => !existing.some(saved => saved.title === row.title && saved.content === row.content));
                    this.data[key] = [...existing, ...additions].slice(0, 20);
                } else if (empty) this.data[key] = value;
            }
            this.data = normaliseSections(this.data);
            await this.go(3);
        } catch (error) { this.importError = error.message; }
        finally { this.importing = false; event.target.value = ''; }
    },
    periodParts(period) {
        const raw = String(period || '').trim();
        const parts = /^(present|current|ongoing|now)$/i.test(raw) ? ['', raw] : /^Until /i.test(raw) ? ['', raw.slice(6)] : raw.split(/\s+[-\u2013\u2014]\s+|\s+to\s+|(?<=\d{4})[-\u2013\u2014](?=\d{4})/i, 2);
        const months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        const asMonth = value => {
            if (/^\d{4}-(0[1-9]|1[0-2])$/.test(value || '')) return value;
            const match = String(value || '').match(/^([a-z]+)\.?\s+(\d{4})$/i);
            const month = match ? months.indexOf(match[1].slice(0, 3).toLowerCase()) : -1;
            return month < 0 ? '' : `${match[2]}-${String(month + 1).padStart(2, '0')}`;
        };
        return { startText: parts[0] || '', endText: parts[1] || '', start: asMonth(parts[0]), end: asMonth(parts[1]), present: /^(present|current|ongoing|now)$/i.test(parts[1] || '') };
    },
    setPeriod(item, part, value, input = null) {
        const parts = this.periodParts(item.period);
        const previous = parts[part];
        if (part !== 'present' && value && !/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/.test(value)) {
            if (input) input.value = previous || '';
            this.error = 'Please choose a valid month and a year between 1 and 9999.';
            return false;
        }
        const format = value => {
            if (!value) return '';
            const [year, month] = value.split('-');
            return `${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(month) - 1]} ${year}`;
        };
        if (part === 'start') { parts.start = value; parts.startText = format(value); }
        if (part === 'end') { parts.end = value; parts.endText = format(value); parts.present = false; }
        if (part === 'present') { parts.present = value; parts.end = ''; parts.endText = value ? 'Present' : ''; }
        if (parts.start && parts.end && parts.end < parts.start) {
            if (input) input.value = previous || '';
            this.error = 'The end month must be the same as or later than the start month.';
            return false;
        }
        this.error = '';
        item.period = parts.startText ? [parts.startText, parts.endText].filter(Boolean).join(' - ') : parts.present ? 'Present' : parts.endText ? `Until ${parts.endText}` : '';
    },
    openMonthPicker(event) {
        try { event.target.showPicker?.(); } catch { /* Keyboard entry remains available. */ }
    },
    add(section) {
        if (!Array.isArray(this.data[section]) || this.data[section].length >= 20) return;
        const blank = { experience: blankExperience, education: blankEducation, achievements: () => ({ title: '', description: '' }), courses: () => ({ title: '', institution: '' }), languages: () => ({ name: '', level: 'Fluent' }) };
        this.data[section].push(blank[section]());
        const labels = { experience: 'Experience entry', education: 'Education entry', achievements: 'Achievement', courses: 'Course', languages: 'Language' };
        this.showSuccess(`${labels[section]} added successfully.`);
    },
    showSuccess(message) {
        clearTimeout(this.successTimer);
        this.successMessage = message;
        this.successTimer = setTimeout(() => { this.successMessage = ''; }, 4000);
    },
    addCustomSection() {
        if (this.data.custom_sections.length >= 20) return;
        this.data.custom_sections.push({ title: 'Additional section', content: '' });
        this.tab = 'custom_sections';
        this.showSuccess('Section added successfully.');
    },
    move(section, index, direction) { const next = index + direction; if (next < 0 || next >= this.data[section].length) return; const rows = this.data[section]; [rows[index], rows[next]] = [rows[next], rows[index]]; },
    atsExample(template) {
        const image = template.id !== 'ats-professional';
        const base = { ...sample, name: 'John Doe', role: image ? '' : 'DATA SCIENCE GRADUATE | WEB DEVELOPER | IT PROFESSIONAL', location: image ? 'London, UK' : 'Harare, Zimbabwe', summary: image ? '' : sample.summary, achievements: [], courses: [], languages: [], custom_sections: [] };
        const responsibility = image ? 'Coordinated cross-functional projects across multiple departments and locations, working with analysts, managers and operational teams to improve service delivery.\nIntroduced clearer reporting and practical workflows through research and collaboration, reducing manual work and improving the accuracy of business information.\nSupported team development through training, documentation and knowledge sharing, helping colleagues adopt new processes and deliver consistent results.' : 'Coordinated cross-functional projects, working with colleagues across departments to improve service delivery.\nIntroduced clearer reporting and practical workflows, reducing manual work and improving accuracy.\nSupported team development through training, documentation and regular knowledge sharing.';
        base.experience = [
            { title: image ? 'Senior Analyst, Business Development & Operations' : 'Freelance Web Developer', company: image ? (template.id === 'ats-compact' ? 'NORTHSTAR & CO.' : 'Northstar & Co.') : '', period: 'Apr 2017 - Present', location: image ? 'London, United Kingdom' : '', description: responsibility + '\nDelivered reliable solutions through research, planning and collaboration with stakeholders.' },
            { title: image ? 'Associate Product Manager' : 'ICT Assistant, Industrial Attachment', company: image ? (template.id === 'ats-compact' ? 'INNOVATE' : 'Innovate') : 'City Health Group', period: 'Apr 2013 - Jun 2015', location: image ? 'Sydney, Australia' : '', description: responsibility },
            ...(image ? [{ title: 'Analyst', company: template.id === 'ats-compact' ? 'NORTHSTAR & CO.' : 'Northstar & Co.', period: 'Jun 2011 - May 2013', location: 'New York, USA', description: responsibility }] : []),
        ];
        base.education = [{ school: image ? (template.id === 'ats-compact' ? 'NORTHSTAR UNIVERSITY' : 'Northstar University') : 'State University', degree: image ? 'Bachelor of Engineering, Computer Science' : 'Bachelor of Commerce (Honours) in Data Science and Informatics', period: image ? 'May 2011' : '2021 - 2025', location: image ? 'Boston, USA' : '' }];
        base.skills = 'Programming: Python, PHP, JavaScript, SQL, HTML5, CSS3\nFrameworks: Laravel\nDatabases: MySQL, MongoDB\nTools: Git, RESTful APIs, Microsoft 365';
        if (template.id === 'ats-compact') {
            base.custom_sections = [{ title: 'Leadership', content: 'Community Business Series - London, United Kingdom\nFounder | Jul 2017 - Present\n- Organised events connecting young professionals with local business leaders.\n- Coordinated networking sessions and community workshops.\nUniversity Events Club - London, United Kingdom\nEvent Manager | Jun 2016 - Jun 2017\n- Planned events and improved reporting for the organising team.' }];
        }
        if (!image) {
            base.courses = [{ title: 'Data Science Professional Certificate', institution: 'Professional Learning Institute' }];
            base.custom_sections = [{ title: 'Projects', content: 'Traffic Optimisation - Final-Year Project\n- Built a data collection and reporting system using Python and MongoDB.\nCommercial Website\n- Developed and deployed a responsive website with PHP and MySQL.\nCommunity Foundation Website\n- Created an accessible website supporting community outreach.' }];
            base.core_strengths = 'Problem Solving | Analytical Skills | Communication | Attention to Detail\nTeam Collaboration | Adaptability | Integrity | Troubleshooting';
            base.references = 'Alex Taylor | Systems Administrator | alex.taylor@example.com\nJordan Smith | Lecturer | jordan.smith@example.com';
        }
        return base;
    },
    skillMarkup(value, grouped = false) {
        const lines = String(value || '').split('\n').map(line => line.trim()).filter(Boolean);
        if (grouped) value = lines.map(line => line.replace(/^[^:]+:\s*/, '')).join(', ');
        const skills = [...new Set(String(value || '').split(/[,;\n|]+/).map(skill => skill.trim()).filter(Boolean))];
        return skills.length ? `<div class="cv-skills">${skills.map(skill => `<span>${escape(skill)}</span>`).join('')}</div>` : '';
    },
    titledContentMarkup(value) {
        const lines = String(value || '').replace(/\r/g, '').split('\n');
        const bullet = line => /^\s*[\u2022*-]\s+/.test(line);
        return `<div class="cv-titled-content">${lines.map((line, index) => {
            const clean = line.trim();
            if (!clean) return '';
            if (bullet(line)) return `<ul><li>${escape(clean.replace(/^[\u2022*-]\s+/, ''))}</li></ul>`;
            const title = index === 0 || !lines[index - 1].trim() || (bullet(lines[index - 1]) && bullet(lines[index + 1] || ''));
            return title && lines.slice(index + 1).some(line => line.trim())
                ? `<p class="cv-item-title"><strong>${escape(clean)}</strong></p>`
                : `<p class="cv-item-description">${escape(clean)}</p>`;
        }).join('')}</div>`;
    },
    referenceMarkup(value) {
        const lines = String(value || '').split('\n').map(line => line.trim()).filter(Boolean);
        return lines.length ? `<div class="cv-references">${lines.map(line => {
            const parts = line.split('|').map(part => part.trim()).filter(Boolean);
            if (parts.length === 1) return `<p class="cv-reference">${escape(parts[0])}</p>`;
            const contact = parts.slice(1);
            const email = contact.filter(part => /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(part));
            const phone = contact.filter(part => !email.includes(part) && (part.match(/\d/g)?.length || 0) >= 7);
            const role = contact.filter(part => !email.includes(part) && !phone.includes(part));
            const cells = [parts[0], role.join(' | '), phone.join(' | '), email.join(' | ')];
            return `<p class="cv-reference-row">${cells.map((part, index) => `<span>${index && part ? ' | ' : ''}${escape(part)}</span>`).join('')}</p>`;
        }).join('')}</div>` : '';
    },
    previewPosition(template, d) {
        const text = value => escape(String(value ?? '').trim());
        const section = (id, title, content) => {
            const heading = id === 'core_strengths' ? 'Competencies' : template.section_labels?.[id] || title;
            return content ? `<section data-section="${id}" class="cv-section">${heading ? `<h3>${text(heading)}</h3>` : ''}${content}</section>` : '';
        };
        const paragraph = value => String(value ?? '').trim() ? `<p>${text(value)}</p>` : '';
        const rows = (items, render) => (items || []).filter(row => Object.entries(row).some(([key, value]) => key !== 'level' && String(value ?? '').trim())).map(render).join('');
        const bullets = value => String(value ?? '').trim() ? `<ul>${String(value).split('\n').filter(Boolean).map(line => `<li>${text(line.replace(/^[\s\u2022*-]+/, ''))}</li>`).join('')}</ul>` : '';
        const experience = section('experience', 'Work Experience', rows(d.experience, row => {
            const department = template.category === 'By Department';
            const heading = department ? [row.company, row.title].filter(Boolean).map(text).join(' - ') : text(row.title);
            const subheading = department ? text(row.location) : `${text(row.company)}${row.location ? ` | ${text(row.location)}` : ''}`;
            return `<div class="cv-entry"><div class="position-entry-heading"><h4>${heading}</h4><span>${text(row.period)}</span></div>${subheading ? `<div class="position-entry-subheading">${subheading}</div>` : ''}${bullets(row.description)}</div>`;
        }));
        const education = section('education', 'Education', rows(d.education, row => `<div class="cv-entry"><div class="position-entry-heading"><h4>${text(row.school)}</h4><span>${text(row.period)}</span></div><p class="position-degree">${text(row.degree)}${row.location ? ` &middot; ${text(row.location)}` : ''}</p>${row.grade ? `<p class="cv-education-grade">${text(row.grade)}</p>` : ''}</div>`));
        const blocks = {
            summary: section('summary', '', paragraph(d.summary)), experience, education,
            skills: section('skills', 'Core Skills', this.skillMarkup(d.skills, template.category === 'By Department')),
            courses: section('courses', 'Certificates', rows(d.courses, row => `<div class="cv-entry"><div class="position-entry-heading"><h4>${text(row.title)}</h4>${row.period ? `<span>${text(row.period)}</span>` : ''}</div><p class="position-entry-subheading">${text(row.institution)}</p></div>`)),
            achievements: section('achievements', 'Achievements', rows(d.achievements, row => `<div class="cv-entry"><h4>${text(row.title)}</h4>${paragraph(row.description)}</div>`)),
            languages: section('languages', 'Languages', rows(d.languages, row => `<p>${text(row.name)}${row.level ? ` &middot; ${text(row.level)}` : ''}</p>`)),
            core_strengths: section('core_strengths', 'Competencies', this.skillMarkup(d.core_strengths)),
            competencies: section('competencies', 'Key Competencies', paragraph(d.competencies)),
            references: section('references', 'References', this.referenceMarkup(d.references)),
            custom_sections: (d.custom_sections || []).map(row => section('custom_sections', row.title, this.titledContentMarkup(row.content))).join(''),
        };
        const order = template.section_order || (template.id === 'position-academic'
            ? ['education', 'experience', 'skills', 'courses', 'custom_sections', 'achievements', 'languages', 'core_strengths', 'competencies', 'references']
            : ['experience', 'custom_sections', 'skills', 'education', 'courses', 'achievements', 'languages', 'core_strengths', 'competencies', 'references']);
        const allOrder = [...order, ...Object.keys(blocks).filter(id => id !== 'summary' && !order.includes(id))];
        const header = `<header data-section="personal" class="cv-header"><div class="position-identity"><h2>${text(d.name)}</h2>${template.hide_role ? '' : `<div class="cv-role">${text(d.role)}</div>`}</div><div class="cv-contact">${[d.email, d.website, d.phone, d.location].filter(value => String(value ?? '').trim()).map(value => `<span>${text(value)}</span>`).join('')}</div></header>`;
        return this.applySectionLayout(`<article data-template="${text(template.id)}" class="cv-paper cv-position${template.category === 'By Department' ? ' cv-department-ats' : ''} template-${text(template.id)} template-${text(template.layout)} layout-${text(template.layout)} font-${text(template.font)}${template.uppercase_name ? ' position-uppercase' : ''}" style="--cv-accent:#333"><div class="position-intro">${header}<div class="cv-lead">${blocks.summary}</div></div><div class="cv-columns"><div class="cv-main">${allOrder.map(id => blocks[id]).join('')}</div><aside class="cv-side"></aside></div></article>`, d);
    },
    previewAts(template, d) {
        const word = template.id === 'ats-professional';
        const compact = template.id === 'ats-compact';
        const text = value => String(value ?? '').trim();
        const section = (id, title, content) => content ? `<section data-section="${id}" class="cv-section"><h3>${escape(title)}</h3>${content}</section>` : '';
        const paragraph = value => text(value) ? `<p>${escape(text(value))}</p>` : '';
        const bullets = value => text(value) ? `<ul>${text(value).split('\n').map(line => {
            const nested = /^\s+(?:[-*\u2022]|[o\u25cb]\s)/.test(line);
            return `<li${nested ? ' class="ats-nested"' : ''}>${escape(line.replace(/^[\s\u2022\u25cb*-]+/, '').replace(/^o\s+/, '').trim())}</li>`;
        }).join('')}</ul>` : '';
        const entries = (items, render) => (items || []).filter(row => Object.entries(row).some(([key, value]) => key !== 'level' && text(value))).map(render).join('');
        const experience = section('experience', word ? 'Professional experience' : 'Experience', entries(d.experience, row => {
            const company = [row.company, compact ? row.location : ''].filter(Boolean).join(' - ');
            if (word) return `<div class="cv-entry"><div class="ats-entry-heading"><strong>${escape([row.company, row.title].filter(Boolean).join(' - '))}</strong><span>${escape(row.period)}</span></div>${paragraph(row.location)}${bullets(row.description)}</div>`;
            return `<div class="cv-entry"><div class="ats-entry-heading"><strong>${escape(company || row.title)}</strong>${compact ? '' : `<span>${escape(row.period)}</span>`}</div><div class="ats-entry-subheading"><span>${row.company ? escape(row.title) : ''}</span><span>${escape(compact ? row.period : row.location)}</span></div>${bullets(row.description)}</div>`;
        }));
        const education = section('education', 'Education', entries(d.education, row => `<div class="cv-entry"><div class="ats-entry-heading"><strong>${escape(word ? row.school || row.degree : [row.school || row.degree, compact ? row.location : ''].filter(Boolean).join(' - '))}</strong><span>${escape(row.period)}</span></div>${word ? paragraph([row.school ? row.degree : '', row.location].filter(Boolean).join(' · ')) : `<div class="ats-entry-subheading"><span>${row.school ? escape(row.degree) : ''}</span>${compact ? '' : `<span>${escape(row.location)}</span>`}</div>`}${row.grade ? paragraph(row.grade) : ''}</div>`));
        const summary = section('summary', 'Professional summary', paragraph(d.summary));
        const skillLines = this.skillMarkup(d.skills, word);
        const skills = section('skills', word ? 'Technical skills' : 'Additional', skillLines);
        const achievements = section('achievements', 'Achievements', entries(d.achievements, row => `<div class="cv-entry"><strong>${escape(row.title)}</strong>${paragraph(row.description)}</div>`));
        const courses = entries(d.courses, row => word
            ? `<div class="cv-entry"><div class="ats-entry-heading"><span>${escape(row.title)}</span><span>${escape(row.period || '')}</span></div>${paragraph(row.institution)}</div>`
            : `<p><strong>${escape(row.title)}</strong>${row.institution ? ' | ' + escape(row.institution) : ''}</p>`);
        const certification = section('courses', word ? 'Certification' : 'Certifications & training', courses);
        const languages = section('languages', 'Languages', entries(d.languages, row => `<p>${escape(row.name)}${row.level ? ' - ' + escape(row.level) : ''}</p>`));
        const custom = d.custom_sections || [];
        const leadership = custom.filter(row => /leadership/i.test(row.title));
        const additional = custom.filter(row => !leadership.includes(row)).map(row => section('custom_sections', row.title, this.titledContentMarkup(row.content))).join('');
        const leadershipSection = leadership.map(row => section('custom_sections', row.title, text(row.content).split('\n').map(line => {
            if (/^[\s\u2022*-]+/.test(line)) return bullets(line);
            const parts = line.split('|');
            return parts.length > 1 ? `<div class="ats-entry-subheading"><span>${escape(parts[0].trim())}</span><span>${escape(parts.slice(1).join('|').trim())}</span></div>` : `<p><strong>${escape(line)}</strong></p>`;
        }).join(''))).join('');
        const strengths = section('core_strengths', 'Competencies', this.skillMarkup(d.core_strengths));
        const references = section('references', 'References', this.referenceMarkup(d.references));
        const imageAdditional = compact ? section('skills', 'Additional', skillLines + (courses ? `<div data-section="courses">${courses}</div>` : '') + entries(d.languages, row => `<p data-section="languages"><strong>Languages:</strong> ${escape(row.name)}${row.level ? ' - ' + escape(row.level) : ''}</p>`)) : skills;
        const content = word ? `${summary}${experience}${education}${certification}${skills}${additional}${leadershipSection}${achievements}${languages}${strengths}${references}` : `${experience}${leadershipSection}${education}${imageAdditional}${summary}${achievements}${compact ? '' : certification}${additional}${strengths}${compact ? '' : languages}${references}`;
        const contacts = word || compact ? [d.location, d.phone, d.email, d.website] : [d.email, d.phone, d.location, d.website];
        return this.applySectionLayout(`<article data-template="${escape(template.id)}" class="cv-paper cv-ats ${word ? 'cv-department-ats cv-word-reference' : ''} layout-${template.layout} font-${template.font}" style="--cv-accent:#111"><header data-section="personal" class="cv-header"><h2>${escape(d.name)}</h2>${word && d.role ? `<p class="cv-role">${escape(d.role)}</p>` : ''}<div class="cv-contact">${contacts.map(text).filter(Boolean).map(value => `<span>${escape(value)}</span>`).join('')}</div></header><div class="ats-content">${content}</div></article>`, d);
    },
    templateStructure(id) {
        const design = this.templates.find(template => template.id === id)?.design_id;
        if (design) return this.templateStructure(design);
        const position = this.templates.find(template => template.id === id && template.section_order);
        if (position) return {lead: ['summary'], main: position.section_order, side: []};
        if (String(id || '').startsWith('position-')) return {lead: ['summary'], main: id === 'position-academic' ? ['education', 'experience', 'skills', 'courses', 'custom_sections', 'achievements', 'languages', 'core_strengths', 'competencies', 'references'] : ['experience', 'custom_sections', 'skills', 'education', 'courses', 'achievements', 'languages', 'core_strengths', 'competencies', 'references'], side: []};
        const plans = {
            'double-column': { main: ['summary', 'experience', 'achievements'], side: ['skills', 'core_strengths', 'competencies', 'education', 'languages'] },
            classic: { main: ['summary', 'experience', 'education', 'skills', 'core_strengths', 'competencies', 'achievements', 'courses', 'languages', 'custom_sections', 'references'], side: [] },
            elegant: { main: ['summary', 'experience', 'education', 'custom_sections', 'references'], side: ['skills', 'core_strengths', 'competencies', 'achievements', 'courses', 'languages'] },
            crest: { main: ['summary', 'experience', 'education'], side: ['achievements', 'courses', 'languages'] },
            minimal: { main: ['summary', 'experience', 'education'], side: [] },
            executive: { main: ['experience', 'education', 'custom_sections', 'references'], side: ['summary', 'skills', 'core_strengths', 'competencies', 'achievements', 'courses', 'languages'] },
            studio: { main: ['summary', 'experience', 'custom_sections'], side: ['education', 'skills', 'core_strengths', 'competencies'] },
            graduate: { main: ['summary', 'education', 'experience', 'custom_sections'], side: ['skills', 'core_strengths', 'competencies', 'courses', 'languages'] },
            horizon: { main: ['summary', 'experience', 'education', 'achievements', 'custom_sections', 'references'], side: ['skills', 'core_strengths', 'competencies', 'courses', 'languages'] },
            editorial: { main: ['summary', 'experience', 'custom_sections'], side: ['education', 'achievements'] },
            precision: { main: ['summary', 'experience', 'education', 'references'], side: ['skills', 'competencies', 'core_strengths', 'courses', 'languages', 'custom_sections'] },
            heritage: { main: ['summary', 'experience', 'education', 'achievements', 'courses', 'custom_sections', 'skills', 'core_strengths', 'competencies', 'languages', 'references'], side: [] },
            nordic: { main: ['summary', 'experience', 'education', 'custom_sections'], side: ['skills', 'core_strengths', 'competencies', 'languages'] },
            momentum: { lead: ['summary', 'skills'], main: ['experience', 'custom_sections'], side: ['education', 'core_strengths', 'competencies', 'achievements', 'courses', 'languages'] },
            scholar: { main: ['summary', 'education', 'courses', 'experience', 'custom_sections'], side: [] },
            signal: { main: ['summary', 'experience', 'achievements'], side: ['education', 'skills', 'core_strengths', 'competencies', 'courses', 'languages', 'custom_sections'] },
            linen: { main: ['summary', 'experience'], side: ['education', 'courses', 'achievements'] },
            portfolio: { main: ['summary', 'experience', 'custom_sections', 'references'], side: ['skills', 'education', 'core_strengths', 'competencies', 'courses', 'languages'] },
            essential: { main: ['summary', 'experience', 'education', 'achievements', 'courses', 'custom_sections', 'references'], side: [] },
        };
        return { lead: [], ...(plans[id] || plans['double-column']) };
    },
    inlineField(path, label, tag = 'p') {
        const current = path.split('.').reduce((value, key) => value?.[key], this.data) ?? '';
        return `<${tag} contenteditable="plaintext-only" role="textbox" aria-label="${escape(label)}" data-field="${escape(path)}" data-placeholder="${escape(label)}" spellcheck="true">${escape(current)}</${tag}>`;
    },
    inlineDates(section, index) {
        const item = this.data[section][index];
        const parts = this.periodParts(item.period);
        const field = (part, label) => `<label>${label}<input type="month" min="0001-01" max="9999-12" data-date-section="${section}" data-date-index="${index}" data-date-part="${part}" aria-label="${section} ${index + 1} ${label.toLowerCase()}" value="${escape(parts[part])}" ${part === 'end' && parts.present ? 'disabled' : ''}></label>`;
        return `<span class="canvas-date-anchor"><button type="button" class="canvas-date-toggle" data-date-toggle="${section}.${index}" aria-expanded="false">${escape(item.period || 'Dates')}</button><span class="canvas-date-popup" hidden><span class="canvas-dates">${field('start', 'Start month and year')}${field('end', 'End month and year')}<label class="canvas-present"><input type="checkbox" data-date-section="${section}" data-date-index="${index}" data-date-part="present" aria-label="${section} ${index + 1} Present" ${parts.present ? 'checked' : ''}> Present</label></span></span></span>`;
    },
    changeInlineDate(event) {
        const input = event.target;
        const { dateSection, dateIndex, datePart } = input.dataset;
        if (!['experience', 'education', 'courses'].includes(dateSection) || !['start', 'end', 'present'].includes(datePart) || this.reviewing) return;
        const item = this.data[dateSection]?.[Number(dateIndex)];
        if (!item) return;
        if (input.validity && !input.validity.valid) { this.error = 'Please choose a valid month and year.'; return; }
        this.setPeriod(item, datePart, datePart === 'present' ? input.checked : input.value, input);
    },
    commitInline(event) {
        const field = event.target.closest('[data-field]');
        if (!field || this.reviewing) return;
        const parts = field.dataset.field.split('.');
        let target = this.data;
        for (const part of parts.slice(0, -1)) target = target?.[part];
        if (!target) return;
        const key = parts.at(-1);
        const limits = { name: 150, role: 150, email: 255, phone: 80, location: 150, website: 255, skills: 2000, title: 200, company: 150, school: 150, degree: 200, period: 150, institution: 200, level: 100 };
        // innerText applies visual text-transform rules and can silently uppercase saved names.
        let text = field.textContent ?? field.innerText;
        if (field.cloneNode) {
            const copy = field.cloneNode(true);
            copy.querySelectorAll('br').forEach(node => node.replaceWith('\n'));
            [...copy.querySelectorAll('div, p, li')].reverse().forEach(node => node.replaceWith('\n' + node.textContent + '\n'));
            text = copy.textContent;
            if (['skills', 'core_strengths'].includes(field.dataset.field) && field.classList.contains('cv-skills')) {
                text = [...copy.childNodes].map(node => node.textContent.trim()).filter(Boolean)
                    .join(field.classList.contains('cv-skills-grouped') ? '\n' : ', ');
            }
            if (parts[0] === 'custom_sections' && key === 'content' && field.classList.contains('cv-titled-content')
                && [...field.children].every(node => node.matches('p, ul'))) {
                text = [...field.children].map((node, index) => {
                    if (node.matches('ul')) return [...node.querySelectorAll('li')].map(item => '- ' + item.textContent.trim()).join('\n');
                    return (index && node.classList.contains('cv-item-title') ? '\n' : '') + node.textContent;
                }).join('\n');
            }
        }
        let value = String(text ?? '').replace(/\r/g, '').trim();
        if (parts[0] === 'references') {
            value = value.split('\n').map(line => line.split('|').map(part => part.trim()).filter(Boolean).join(' | ')).filter(Boolean).join('\n');
        }
        if (parts[0] === 'section_titles' && (!value || value.length > 100)) { this.error = 'Use a section heading between 1 and 100 characters.'; return; }
        if (value.length > (limits[key] || 5000)) { this.error = 'This field is too long. Please shorten it.'; return; }
        if (parts[0] === 'languages' && key === 'level' && value && !['Native', 'Fluent', 'Advanced', 'Intermediate', 'Beginner'].includes(value)) { this.error = 'Use Native, Fluent, Advanced, Intermediate or Beginner for proficiency.'; return; }
        target[key] = value;
        const nextField = event.relatedTarget?.dataset?.field;
        if (nextField && this.$nextTick) this.$nextTick(() => this.$root.querySelector(`[data-field="${nextField}"]`)?.focus());
    },
    pasteInline(event) {
        if (!event.target.closest('[data-field]')) return;
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    },
    canvasAction(event) {
        const dateToggle = event.target.closest('[data-date-toggle]');
        if (dateToggle) {
            event.preventDefault?.();
            const popup = dateToggle.nextElementSibling;
            popup.hidden = !popup.hidden;
            dateToggle.setAttribute('aria-expanded', String(!popup.hidden));
            return;
        }
        const action = event.target.closest('[data-canvas-action]');
        if (!action || this.reviewing) return;
        event.preventDefault?.();
        const activeField = globalThis.document?.activeElement;
        if (activeField?.matches('[data-field]')) this.commitInline({target: activeField});
        const section = action.dataset.sectionId;
        if (action.dataset.canvasAction === 'edit-section') {
            const path = action.closest('.cv-section').querySelector('h3, [data-field]')?.dataset.field;
            this.$nextTick(() => {
                const heading = path ? this.$root.querySelector(`[data-field="${path}"]`) : null;
                if (!heading) return;
                heading.focus();
                const range = document.createRange(); range.selectNodeContents(heading);
                const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
            });
            return;
        }
        if (action.dataset.canvasAction === 'delete-section') {
            if (!this.sections.some(item => item.id === section && item.id !== 'personal')) return;
            if (section === 'custom_sections' && action.dataset.customIndex !== undefined) {
                this.data.custom_sections.splice(Number(action.dataset.customIndex), 1);
                if (this.data.custom_sections.length) return;
            }
            this.data[section] = this.inlineSchemas()[section] ? [] : '';
            if (!this.data.hidden_sections.includes(section)) this.data.hidden_sections.push(section);
            this.inlineSections = this.inlineSections.filter(id => id !== section);
            return;
        }
        if (action.dataset.canvasAction === 'open-insert') {
            const menu = action.nextElementSibling; menu.hidden = !menu.hidden; return;
        }
        if (action.dataset.canvasAction === 'insert-section') {
            const selected = action.closest('.canvas-insert').querySelector('select').value;
            this.addSectionId = selected;
            this.data.section_positions = this.data.section_positions.filter(item => item.section !== selected);
            this.data.section_positions.push({section: selected, region: action.dataset.region, after: action.dataset.after || null});
            this.showInlineSection(); return;
        }
        if (action.dataset.canvasAction === 'move-section') {
            const node = action.closest('.cv-section');
            const siblings = [...node.parentElement.children].filter(item => item.matches('.cv-section'));
            const index = siblings.indexOf(node);
            const direction = Number(action.dataset.direction);
            const neighbor = siblings[index + direction];
            if (!neighbor) return;
            const region = action.dataset.region;
            const after = direction < 0 ? siblings[index - 2]?.dataset.section : neighbor.dataset.section;
            this.data.section_positions = this.data.section_positions.filter(item => item.section !== section);
            this.data.section_positions.push({section, region, after: after || null});
            return;
        }

        if (action.dataset.canvasAction === 'add') {
            const fields = this.inlineSchemas()[section];
            if (fields && this.data[section].length < 20) this.data[section].push(Object.fromEntries(Object.keys(fields).map(key => [key, section === 'custom_sections' && key === 'title' ? 'Additional section' : ''])));
        } else if (action.dataset.canvasAction === 'remove') this.data[section].splice(Number(action.dataset.index), 1);
    },
    inlineSchemas() {
        return {
            experience: { title: 'Job title', company: 'Company', period: 'Dates', location: 'Location', description: 'Your responsibilities and achievements' },
            education: { degree: 'Degree or qualification', school: 'School or university', period: 'Dates', location: 'Location', grade: 'Degree class / grade (optional)' },
            achievements: { title: 'Achievement', description: 'Describe your achievement' },
            courses: { title: 'Course title', institution: 'Institution', period: 'Dates' },
            languages: { name: 'Language', level: 'Proficiency' },
            custom_sections: { title: 'Section title', content: 'Title on one line, description underneath. Leave a blank line between entries.' },
        };
    },
    showInlineSection() {
        this.data.hidden_sections = this.data.hidden_sections.filter(id => id !== this.addSectionId);
        if (!this.inlineSections.includes(this.addSectionId)) this.inlineSections.push(this.addSectionId);
        const schema = this.inlineSchemas()[this.addSectionId];
        if (schema && !this.data[this.addSectionId].length) this.data[this.addSectionId].push(Object.fromEntries(Object.keys(schema).map(key => [key, key === 'title' && this.addSectionId === 'custom_sections' ? 'Additional section' : ''])));
        this.$nextTick(() => this.$root.querySelector(`[data-section="${this.addSectionId}"]`)?.scrollIntoView({block: 'center', behavior: 'smooth'}));
    },
    editablePreview(template) {
        // Render the selected template's own markup, then attach editing controls
        // only to its text. Keep its columns, headings, accents and entry layout.
        const data = JSON.parse(JSON.stringify(this.data));
        const replacements = [];
        const marker = (path, label, dates = null) => {
            const token = `CVEDITFIELD${replacements.length}TOKEN`;
            replacements.push([token, dates ? this.inlineDates(...dates) : this.inlineField(path, label, 'span')]);
            return token;
        };
        for (const [key, label] of Object.entries({name: 'Your full name', role: 'Your position', email: 'Email', phone: 'Phone', location: 'Location', website: 'Website'})) data[key] = marker(key, label);
        const schemas = this.inlineSchemas();
        for (const section of this.sections.filter(section => section.id !== 'personal')) {
            const original = this.data[section.id];
            const hasContent = Array.isArray(original) ? original.some(row => Object.entries(row).some(([key, text]) => key !== 'level' && String(text ?? '').trim())) : Boolean(String(original ?? '').trim());
            const structure = this.templateStructure(template.id);
            const templateSections = [...structure.lead, ...structure.main, ...structure.side];
            const isDesignSection = ['achievements', 'courses', 'languages'].includes(section.id) && templateSections.includes(section.id);
            const visible = !this.data.hidden_sections.includes(section.id) && (hasContent || isDesignSection || ['summary', 'experience', 'education', 'skills'].includes(section.id) || this.inlineSections.includes(section.id));
            if (!visible) { data[section.id] = schemas[section.id] ? [] : ''; continue; }
            if (schemas[section.id]) {
                data[section.id] = (original || []).map((row, index) => Object.fromEntries(Object.entries(schemas[section.id]).map(([key, label]) => [key, section.id === 'custom_sections' && key === 'title' ? row.title : marker(`${section.id}.${index}.${key}`, label, key === 'period' ? [section.id, index] : null)])));
            } else data[section.id] = marker(section.id, template.section_labels?.[section.id] || section.label);
        }
        let html = this.preview(template, false, data);
        // Date controls are block elements; keep them out of inline text wrappers.
        for (const [token, field] of replacements) html = html.split(token).join(field);
        const document = new DOMParser().parseFromString(html, 'text/html');
        const paper = document.querySelector('.cv-paper');
        for (const field of paper.querySelectorAll('[data-field^="custom_sections."][data-field$=".content"]')) {
            const container = field.closest('.cv-titled-content');
            if (!container) continue;
            for (const attribute of field.attributes) container.setAttribute(attribute.name, attribute.value);
            const index = Number(field.dataset.field.split('.')[1]);
            const formatted = document.createElement('div');
            formatted.innerHTML = this.titledContentMarkup(this.data.custom_sections[index]?.content);
            container.innerHTML = formatted.firstElementChild?.innerHTML || '<p></p>';
        }
        for (const fieldName of ['skills', 'core_strengths']) {
            const skillField = paper.querySelector(`[data-field="${fieldName}"]`);
            if (skillField) {
                const grid = skillField.closest('.cv-skills');
                if (grid) {
                    for (const attribute of skillField.attributes) grid.setAttribute(attribute.name, attribute.value);
                    grid.classList.add('cv-skills');
                    const formatted = document.createElement('div');
                    formatted.innerHTML = this.skillMarkup(this.data[fieldName], fieldName === 'skills' && (template.category === 'By Department' || template.id === 'ats-professional'));
                    grid.classList.toggle('cv-skills-grouped', Boolean(formatted.firstElementChild?.classList.contains('cv-skills-grouped')));
                    grid.innerHTML = formatted.firstElementChild?.innerHTML || '<span></span>';
                    grid.firstElementChild.dataset.placeholder = fieldName === 'skills' ? 'Add a skill' : 'Add a strength';
                }
            }
        }
        const referenceField = paper.querySelector('[data-field="references"]');
        if (referenceField) {
            const container = referenceField.closest('.cv-references');
            if (container) {
                for (const attribute of referenceField.attributes) container.setAttribute(attribute.name, attribute.value);
                container.classList.add('cv-references');
                const formatted = document.createElement('div');
                formatted.innerHTML = this.referenceMarkup(this.data.references);
                container.innerHTML = formatted.firstElementChild?.innerHTML || '<p></p>';
            }
        }
        // Keep each responsibility as a real list item while editing the whole
        // description as one field. Enter creates another visible bullet.
        for (const field of paper.querySelectorAll('[data-field^="experience."][data-field$=".description"]')) {
            const list = field.closest('ul');
            if (!list) continue;
            const index = Number(field.dataset.field.split('.')[1]);
            const lines = String(this.data.experience[index]?.description || '').split('\n')
                .map(line => line.replace(/^[\s\u2022*-]+/, '').trim()).filter(Boolean);
            for (const attribute of field.attributes) list.setAttribute(attribute.name, attribute.value);
            list.classList.add('cv-experience-points');
            list.innerHTML = (lines.length ? lines : ['']).map(line => `<li>${escape(line)}</li>`).join('');
            if (!lines.length) list.firstElementChild.dataset.placeholder = 'Your responsibilities and achievements';
        }
        for (const [id, schema] of Object.entries(schemas)) {
            let sections = paper.querySelectorAll(`[data-section="${id}"]`);
            if (!sections.length && !this.data.hidden_sections.includes(id) && (['experience', 'education'].includes(id) || this.inlineSections.includes(id))) {
                const section = document.createElement('section'); section.className = 'cv-section'; section.dataset.section = id;
                section.innerHTML = `<h3>${escape(template.section_labels?.[id] || this.sections.find(section => section.id === id).label)}</h3>`;
                const structure = this.templateStructure(template.id);
                const container = structure.side.includes(id) ? '.cv-side' : structure.lead.includes(id) ? '.cv-lead' : '.cv-main';
                (paper.querySelector(container) || paper.querySelector('.ats-content') || paper).appendChild(section);
                sections = paper.querySelectorAll(`[data-section="${id}"]`);
            }
            if (!sections.length) continue;
            if (id === 'custom_sections') sections.forEach(section => {
                const heading = section.querySelector('h3');
                const index = this.data.custom_sections.findIndex(row => row.title === heading?.textContent);
                if (index >= 0) { heading.setAttribute('contenteditable', 'plaintext-only'); heading.dataset.field = `custom_sections.${index}.title`; heading.setAttribute('aria-label', 'Section title'); }
            });
            const entries = sections[0].querySelectorAll('.cv-entry');
            entries.forEach((entry, index) => {
                entry.insertAdjacentHTML('beforeend', `<button type="button" class="canvas-remove" data-canvas-action="remove" data-section-id="${id}" data-index="${index}">Remove</button>`);
            });
            sections[sections.length - 1].insertAdjacentHTML('beforeend', `<button type="button" class="canvas-add" data-canvas-action="add" data-section-id="${id}">+ Add ${escape(this.sections.find(section => section.id === id).label.toLowerCase())}</button>`);
        }
        const regions = [...paper.querySelectorAll('.cv-main, .cv-side, .cv-lead, .cv-footer, .ats-content')];
        const regionName = node => ['cv-main', 'cv-side', 'cv-lead', 'cv-footer', 'ats-content'].find(name => node.classList.contains(name)) || 'cv-main';
        const options = this.sections.filter(section => section.id !== 'personal').map(section => `<option value="${section.id}">${escape(template.section_labels?.[section.id] || section.label)}</option>`).join('');
        const insertion = (region, after = '') => `<span class="canvas-insert"><button type="button" data-canvas-action="open-insert" aria-label="Insert section here" class="canvas-insert-plus">+</button><span class="canvas-insert-menu" hidden><select aria-label="Section to insert">${options}</select><button type="button" data-canvas-action="insert-section" data-region="${region}" data-after="${after}">Add here</button></span></span>`;
        const trash = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
        regions.forEach(region => {
            const name = regionName(region);
            region.insertAdjacentHTML('afterbegin', insertion(name));
            [...region.children].filter(node => node.matches('.cv-section')).forEach(node => {
                const id = node.dataset.section;
                const customIndex = id === 'custom_sections' ? node.querySelector('[data-field^="custom_sections."]')?.dataset.field.split('.')[1] : undefined;
                const customAttribute = customIndex === undefined ? '' : ` data-custom-index="${customIndex}"`;
                const heading = node.querySelector('h3');
                if (heading && !heading.dataset.field) {
                    heading.setAttribute('contenteditable', 'plaintext-only');
                    heading.setAttribute('role', 'textbox');
                    heading.setAttribute('aria-label', `Edit ${id} heading`);
                    heading.dataset.field = `section_titles.${id}`;
                }
                const pencil = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m16 3 5 5M3 21l5-1L21 7a2.1 2.1 0 0 0-4-4L4 16l-1 5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
                const add = `<button type="button" class="canvas-toolbar-entry" data-canvas-action="edit-section" data-section-id="${id}" aria-label="Edit ${escape(id)} heading" title="Edit heading">${pencil}</button>`;
                node.insertAdjacentHTML('afterbegin', `<span class="canvas-section-toolbar" role="group" aria-label="${escape(id)} section controls">${add}<button type="button" data-canvas-action="delete-section" data-section-id="${id}"${customAttribute} aria-label="Delete ${escape(id)} section">${trash}</button><button type="button" data-canvas-action="move-section" data-section-id="${id}" data-region="${name}" data-direction="-1" aria-label="Move section up">&uarr;</button><button type="button" data-canvas-action="move-section" data-section-id="${id}" data-region="${name}" data-direction="1" aria-label="Move section down">&darr;</button></span>`);
                node.insertAdjacentHTML('beforeend', insertion(name, id));
            });
        });
        return paper.outerHTML;
    },
    paginateDepartment(paper) {
        // Measure the actual document at its print width, never shrink its text.
        const host = window.document.createElement('div');
        host.style.cssText = 'position:absolute;left:-100000px;top:0;width:816px;visibility:hidden;pointer-events:none';
        const pages = window.document.createElement('div');
        pages.className = 'cv-document-pages';
        host.append(pages); window.document.body.append(host);
        let page;
        const newPage = () => {
            page = paper.cloneNode(false);
            page.classList.add('cv-document-page');
            pages.append(page);
        };
        const overflows = () => page.lastElementChild && page.lastElementChild.getBoundingClientRect().bottom > page.getBoundingClientRect().bottom - 33 + 1;
        const header = paper.querySelector('.cv-header');
        newPage(); if (header) page.append(header.cloneNode(true));
        const sections = [...paper.querySelectorAll('.cv-section')];
        try {
            for (const original of sections) {
                let section;
                const startSection = () => {
                    section = original.cloneNode(false);
                    const title = original.querySelector(':scope > h3');
                    if (title) section.append(title.cloneNode(true));
                    page.append(section);
                };
                startSection();
                const units = [...original.children].filter(node => !node.matches('h3,.canvas-empty-slot'));
                const place = node => {
                    section.append(node);
                    if (!overflows()) return;
                    const tooTall = node.getBoundingClientRect().height > 945;
                    node.remove();
                    if (!tooTall) {
                        if (section.children.length <= 1) section.remove();
                        if (page.children.length) newPage();
                        startSection();
                    }
                    section.append(node);
                    if (!overflows()) return;
                    // A single long job can span pages at bullet boundaries.
                    const list = node.querySelector(':scope > ul');
                    if (list && list.children.length > 1) {
                        node.remove();
                        let fragment = node.cloneNode(true);
                        fragment.querySelector('ul').replaceChildren();
                        section.append(fragment);
                        for (const bullet of [...list.children]) {
                            let target = fragment.querySelector('ul');
                            target.append(bullet.cloneNode(true));
                            if (overflows()) {
                                target.lastElementChild.remove();
                                if (!target.children.length) fragment.remove();
                                newPage(); startSection();
                                fragment = node.cloneNode(true);
                                fragment.querySelector('ul').replaceChildren(bullet.cloneNode(true));
                                section.append(fragment);
                            }
                        }
                        return;
                    }
                    // Unusually long summaries/custom paragraphs must also
                    // continue onto another page without clipping or shrinking.
                    if (node.matches('p,div') && !list) {
                        const words = node.textContent.trim().split(/\s+/);
                        node.remove();
                        let fragment = node.cloneNode(false);
                        section.append(fragment);
                        for (const word of words) {
                            const previous = fragment.textContent;
                            fragment.textContent = previous ? `${previous} ${word}` : word;
                            if (overflows() && previous) {
                                fragment.textContent = previous;
                                newPage(); startSection();
                                fragment = node.cloneNode(false);
                                fragment.textContent = word;
                                section.append(fragment);
                            }
                        }
                    }
                };
                for (const unit of units) place(unit.cloneNode(true));
            }
            return pages.outerHTML;
        } finally { host.remove(); }
    },
    reviewPreview(template) {
        const document = new DOMParser().parseFromString(this.editablePreview(template), 'text/html');
        const paper = document.querySelector('.cv-paper');
        paper.classList.add('cv-review');
        for (const section of paper.querySelectorAll('.cv-section:not([data-section="custom_sections"])')) {
            const value = this.data[section.dataset.section];
            const filled = Array.isArray(value)
                ? value.some(row => Object.entries(row).some(([key, text]) => key !== 'level' && String(text ?? '').trim()))
                : Boolean(String(value ?? '').trim());
            if (!filled) section.remove();
        }
        paper.querySelectorAll('.cv-contact [data-field]').forEach(field => {
            if (!field.textContent.trim()) {
                const contact = field.parentElement;
                if (contact.parentElement?.classList.contains('cv-contact')) contact.remove();
                else field.remove();
            }
        });
        paper.querySelectorAll('[contenteditable]').forEach(field => {
            if (!field.textContent.trim()) {
                const previous = field.previousSibling;
                const next = field.nextSibling;
                if (previous?.nodeType === 3) previous.textContent = previous.textContent.replace(/\s*[·|]\s*$/, '');
                if (next?.nodeType === 3) next.textContent = next.textContent.replace(/^\s*[·|]\s*/, '');
            }
            field.setAttribute('contenteditable', 'false');
            field.removeAttribute('role');
            field.removeAttribute('aria-label');
        });
        paper.querySelectorAll('.canvas-section-toolbar, .canvas-add, .canvas-remove, .canvas-date-popup').forEach(control => control.remove());
        paper.querySelectorAll('.canvas-insert').forEach(control => { control.className = 'canvas-empty-slot'; control.replaceChildren(); });
        paper.querySelectorAll('.canvas-date-toggle').forEach(button => {
            const [section, index] = (button.dataset.dateToggle || '').split('.');
            const period = this.data[section]?.[Number(index)]?.period || '';
            if (!period.trim()) { button.remove(); return; }
            const text = document.createElement('span');
            text.className = 'canvas-date-toggle'; text.textContent = period;
            button.replaceWith(text);
        });
        return template.category === 'By Department' || template.id === 'ats-professional' ? this.paginateDepartment(paper) : paper.outerHTML;
    },
    applySectionLayout(html, data) {
        if (!data.hidden_sections?.length && !data.section_positions?.length && !Object.keys(data.section_titles || {}).length) return html;
        const document = new DOMParser().parseFromString(html, 'text/html');
        const paper = document.querySelector('.cv-paper');
        for (const [id, title] of Object.entries(data.section_titles || {})) {
            paper.querySelectorAll(`[data-section="${id}"] > h3`).forEach(heading => { heading.textContent = title; });
        }
        for (const id of data.hidden_sections || []) paper.querySelectorAll(`[data-section="${id}"]`).forEach(node => node.remove());
        for (const placement of data.section_positions || []) {
            const region = paper.querySelector(`.${placement.region}`) || paper.querySelector('.ats-content') || paper.querySelector('.cv-main');
            if (!region) continue;
            const nodes = [...paper.querySelectorAll(`[data-section="${placement.section}"]`)];
            let previous = [...region.children].find(node => node.dataset.section === placement.after);
            for (const node of nodes) {
                if (node.contains(region)) continue;
                if (previous) previous.after(node);
                else if (placement.after) region.append(node);
                else region.prepend(node);
                previous = node;
            }
        }
        return paper.outerHTML;
    },
    preview(template, example = false, sourceData = null) {
        // Gallery cards always show the full design. Draft content belongs to
        // the chosen template in the editor, where it must remain untouched.
        const useExample = example;
        const d = normaliseSections(sourceData || (useExample ? (template.category === 'By Department' ? this.departmentExample(template) : template.category === 'ATS Pass' ? this.atsExample(template) : { ...sample, role: this.data.role || sample.role }) : this.data));
        if (template.design_id) template = this.templates.find(item => item.id === template.design_id) || template;
        if (['By Position', 'By Department'].includes(template.category)) return this.previewPosition(template, d);
        if (template.category === 'ATS Pass') return this.previewAts(template, d);
        const value = text => escape(String(text ?? '').trim());
        const section = (id, title, content) => content ? `<section data-section="${id}" class="cv-section"><h3>${title}</h3>${content}</section>` : '';
        const rows = (items, render) => (items || []).filter(row => Object.entries(row).some(([field, text]) => field !== 'level' && String(text ?? '').trim())).map(render).join('');
        const experience = section('experience', 'Experience', rows(d.experience, r => `<div class="cv-entry"><h4>${value(r.title, 'Your role')}</h4><b class="cv-accent">${value(r.company, 'Company name')}</b><small>${value(r.period, 'Date period')} ${r.location ? ' · ' + escape(r.location) : ''}</small>${r.description ? `<ul>${r.description.split('\n').filter(Boolean).map(line => `<li>${escape(line.replace(/^[•\-]\s*/, ''))}</li>`).join('')}</ul>` : ''}</div>`));
        const education = section('education', 'Education', rows(d.education, r => `<div class="cv-entry"><h4>${value(r.degree, 'Degree and field of study')}</h4><b class="cv-accent">${value(r.school, 'School or university')}</b><small>${value(r.period, 'Date period')} ${r.location ? ' · ' + escape(r.location) : ''}</small>${r.grade ? `<p class="cv-education-grade">${value(r.grade)}</p>` : ''}</div>`));
        const achievements = section('achievements', 'Key achievements', rows(d.achievements, r => `<div class="cv-entry"><h4>${value(r.title, 'Your achievement')}</h4><p>${value(r.description, 'Describe what you did and its impact.')}</p></div>`));
        const skills = section('skills', 'Skills', this.skillMarkup(d.skills));
        const courses = section('courses', 'Training / courses', rows(d.courses, r => `<div class="cv-entry"><h4>${value(r.title, 'Course title')}</h4><p>${value(r.institution, 'Institution or provider')}</p></div>`));
        const languages = section('languages', 'Languages', rows(d.languages, r => `<div class="cv-entry cv-language"><div><h4>${value(r.name, 'Language')}</h4><p>${value(r.level)}</p></div><span class="cv-language-level" aria-hidden="true">${'\u25B0'.repeat({ Native: 5, Fluent: 5, Advanced: 4, Intermediate: 3, Beginner: 1 }[r.level] || 3)}</span></div>`));
        const summary = section('summary', 'Summary', String(d.summary || '').trim() ? `<p>${value(d.summary)}</p>` : '');
        const blocks = {
            summary, experience, education, achievements, skills, courses, languages,
            core_strengths: String(d.core_strengths || '').trim() ? section('core_strengths', 'Competencies', this.skillMarkup(d.core_strengths)) : '',
            competencies: String(d.competencies || '').trim() ? section('competencies', 'Key competencies', `<p>${escape(d.competencies)}</p>`) : '',
            references: String(d.references || '').trim() ? section('references', 'References', this.referenceMarkup(d.references)) : '',
            custom_sections: (d.custom_sections || []).filter(row => String(row.content || '').trim()).map(row => section('custom_sections', escape(row.title), this.titledContentMarkup(row.content))).join(''),
        };
        const structure = this.templateStructure(template.id);
        const assigned = new Set([...structure.lead, ...structure.main, ...structure.side]);
        const footer = Object.keys(blocks).filter(id => !assigned.has(id));
        const render = ids => ids.map(id => blocks[id]).join('');
        const lead = render(structure.lead);
        const remaining = render(footer);
        return this.applySectionLayout(`<article data-template="${escape(template.id)}" class="cv-paper template-${escape(template.id)} layout-${template.layout} font-${template.font}" style="--cv-accent:${template.accent}"><header data-section="personal" class="cv-header"><h2>${value(d.name)}</h2><div class="cv-role">${value(d.role)}</div><div class="cv-contact">${[d.phone, d.email, d.website, d.location].map(text => String(text ?? '').trim()).filter(Boolean).map(text => `<span>${escape(text)}</span>`).join('')}</div></header>${lead ? `<div class="cv-lead">${lead}</div>` : ''}<div class="cv-columns"><div class="cv-main">${render(structure.main)}</div><aside class="cv-side">${render(structure.side)}</aside></div>${remaining ? `<footer class="cv-footer">${remaining}</footer>` : ''}</article>`, d);
    },
}));
