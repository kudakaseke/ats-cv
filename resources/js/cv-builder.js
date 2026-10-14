import Alpine from 'alpinejs';

const blankExperience = () => ({ title: '', company: '', period: '', location: '', description: '' });
const blankEducation = () => ({ degree: '', school: '', period: '', location: '' });
const defaults = () => ({ template: 'double-column', role: '', name: '', email: '', phone: '', location: '', website: '', summary: '', skills: '', experience: [blankExperience()], education: [blankEducation()], achievements: [{ title: '', description: '' }], courses: [{ title: '', institution: '' }], languages: [{ name: '', level: 'Fluent' }], imported_text: '' });
const sample = { ...defaults(), name: 'Alex Morgan', role: 'Your next career opportunity', email: 'alex@example.com', phone: '+27 71 234 5678', location: 'Johannesburg, South Africa', website: 'linkedin.com/in/alexmorgan', summary: 'A thoughtful professional who turns complex challenges into practical results. Experienced in building strong relationships, improving processes, and delivering work that makes a difference.', skills: 'Project planning, Communication, Leadership, Problem solving, Research, Collaboration', experience: [{ title: 'Senior Specialist', company: 'Northstar Group', period: '2022 – Present', location: 'Johannesburg', description: 'Led cross-functional projects from idea to delivery.\nImproved team workflows and introduced a clear approach to reporting.\nBuilt lasting relationships with clients and colleagues.' }, { title: 'Specialist', company: 'Bright Futures', period: '2019 – 2022', location: 'Cape Town', description: 'Supported a growing team across daily operations and strategic initiatives.\nDeveloped practical solutions through research and collaboration.' }, { title: 'Associate', company: 'Common Ground', period: '2017 – 2019', location: 'Pretoria', description: 'Coordinated projects and maintained accurate documentation.\nWorked closely with colleagues to deliver excellent service.' }], education: [{ degree: 'Bachelor of Commerce', school: 'University of Johannesburg', period: '2013 – 2016', location: 'Johannesburg' }], achievements: [{ title: 'Making an impact', description: 'Delivered a successful initiative through careful planning and teamwork.' }, { title: 'Recognised for excellence', description: 'Received recognition for consistent work and attention to detail.' }], courses: [{ title: 'Professional Development', institution: 'Continuing Education Institute' }], languages: [{ name: 'English', level: 'Fluent' }, { name: 'Shona', level: 'Native' }] };
const escape = (text) => String(text ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

Alpine.data('cvBuilder', (boot) => ({
    step: boot.draft.step || 1, data: { ...defaults(), ...(boot.draft.data || {}) }, templates: boot.templates,
    category: 'All', tab: 'personal', importing: false, importError: '', saveState: 'Your draft stays in this browser session', busy: false, error: '', timer: null, saveQueue: Promise.resolve(),
    init() {
        this.$watch('data', () => { clearTimeout(this.timer); this.saveState = 'Unsaved changes'; this.timer = setTimeout(() => this.save(), 900); });
    },
    get selected() { return this.templates.find(t => t.id === this.data.template) || this.templates[0]; },
    get recommendedIds() {
        if (!this.data.role.trim()) return [];
        if (/law|account|financ|legal|bank/i.test(this.data.role)) return ['classic', 'executive', 'heritage'];
        if (/design|creativ|market|art|content/i.test(this.data.role)) return ['studio', 'editorial', 'portfolio'];
        if (/student|graduate|intern|entry/i.test(this.data.role)) return ['graduate', 'minimal', 'essential'];
        return ['double-column', 'elegant', 'precision'];
    },
    get visibleTemplates() { return this.templates.filter(t => this.category === 'All' || t.category === this.category).sort((a, b) => Number(this.recommendedIds.includes(b.id)) - Number(this.recommendedIds.includes(a.id))); },
    get sections() { return [{ id: 'personal', label: 'Personal details' }, { id: 'summary', label: 'Summary' }, { id: 'experience', label: 'Experience' }, { id: 'education', label: 'Education' }, { id: 'skills', label: 'Skills' }, { id: 'achievements', label: 'Achievements' }, { id: 'courses', label: 'Courses' }, { id: 'languages', label: 'Languages' }]; },
    async go(step) { this.step = step; this.error = ''; await this.save(); window.scrollTo({ top: 0, behavior: 'smooth' }); },
    async choose(template) { this.data.template = template.id; await this.go(4); },
    async save() {
        clearTimeout(this.timer);
        const payload = JSON.stringify({ step: this.step, data: this.data });
        const task = this.saveQueue.catch(() => {}).then(async () => {
            this.saveState = 'Saving…';
            try {
                const response = await fetch(boot.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf }, body: payload });
                const result = await response.json();
                if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to save. Please try again.');
                this.saveState = boot.savedAccount ? 'Saved to your account' : 'Draft saved for this session';
                this.error = '';
                return true;
            } catch (error) { this.saveState = 'Could not save'; this.error = error.message; return false; }
        });
        this.saveQueue = task;
        return task;
    },
    async finish() {
        if (!this.data.name.trim()) { this.tab = 'personal'; this.error = 'Please enter your name before continuing.'; return; }
        this.busy = true;
        if (await this.save()) window.location.assign(boot.finishUrl);
        else this.busy = false;
    },
    async upload(event) {
        const file = event.target.files?.[0]; if (!file) return;
        if (file.size > 5 * 1024 * 1024) { this.importError = 'Please choose a file smaller than 5 MB.'; return; }
        this.importing = true; this.importError = '';
        const body = new FormData(); body.append('file', file);
        try {
            const response = await fetch(boot.importUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf }, body });
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || 'The file could not be imported. Please try again.');
            this.data.imported_text = result.text;
            if (!this.data.email) this.data.email = result.email;
            await this.go(2);
        } catch (error) { this.importError = error.message; }
        finally { this.importing = false; event.target.value = ''; }
    },
    add(section) {
        if (this.data[section].length >= 20) return;
        const blank = { experience: blankExperience, education: blankEducation, achievements: () => ({ title: '', description: '' }), courses: () => ({ title: '', institution: '' }), languages: () => ({ name: '', level: 'Fluent' }) };
        this.data[section].push(blank[section]());
    },
    move(section, index, direction) { const next = index + direction; if (next < 0 || next >= this.data[section].length) return; const rows = this.data[section]; [rows[index], rows[next]] = [rows[next], rows[index]]; },
    preview(template, example = false) {
        const d = example ? { ...sample, role: this.data.role || sample.role } : this.data;
        const value = (text, fallback = '') => escape(text || (example ? '' : fallback));
        const section = (id, title, content) => `<section data-section="${id}" class="cv-section"><h3>${title}</h3>${content}</section>`;
        const rows = (items, render) => items.map(render).join('');
        const experience = section('experience', 'Experience', rows(d.experience, r => `<div class="cv-entry"><h4>${value(r.title, 'Your role')}</h4><b class="cv-accent">${value(r.company, 'Company name')}</b><small>${value(r.period, 'Date period')} ${r.location ? ' · ' + escape(r.location) : ''}</small>${r.description ? `<ul>${r.description.split('\n').filter(Boolean).map(line => `<li>${escape(line.replace(/^[•\-]\s*/, ''))}</li>`).join('')}</ul>` : '<p class="cv-placeholder">Describe your work and accomplishments.</p>'}</div>`));
        const education = section('education', 'Education', rows(d.education, r => `<div class="cv-entry"><h4>${value(r.degree, 'Degree and field of study')}</h4><b class="cv-accent">${value(r.school, 'School or university')}</b><small>${value(r.period, 'Date period')} ${r.location ? ' · ' + escape(r.location) : ''}</small></div>`));
        const achievements = section('achievements', 'Key achievements', rows(d.achievements, r => `<div class="cv-entry"><h4>${value(r.title, 'Your achievement')}</h4><p>${value(r.description, 'Describe what you did and its impact.')}</p></div>`));
        const skills = section('skills', 'Skills', `<div class="cv-skills">${(d.skills ? d.skills.split(/[,\n]/).map(x => x.trim()).filter(Boolean) : ['Your skills']).map(skill => `<span>${escape(skill)}</span>`).join('')}</div>`);
        const courses = section('courses', 'Training / courses', rows(d.courses, r => `<div class="cv-entry"><h4>${value(r.title, 'Course title')}</h4><p>${value(r.institution, 'Institution or provider')}</p></div>`));
        const languages = section('languages', 'Languages', rows(d.languages, r => `<div class="cv-entry cv-language"><div><h4>${value(r.name, 'Language')}</h4><p>${value(r.level)}</p></div><span class="cv-language-level" aria-hidden="true">${'\u25B0'.repeat({ Native: 5, Fluent: 5, Advanced: 4, Intermediate: 3, Beginner: 1 }[r.level] || 3)}</span></div>`));
        const summary = section('summary', 'Summary', `<p>${value(d.summary, 'Briefly explain your experience, strengths, and the value you bring to your next role.')}</p>`);
        return `<article class="cv-paper layout-${template.layout} font-${template.font}" style="--cv-accent:${template.accent}"><header data-section="personal" class="cv-header"><h2>${value(d.name, 'YOUR NAME')}</h2><div class="cv-role">${value(d.role, 'The role you are applying for')}</div><div class="cv-contact">${[d.phone || (!example ? 'Phone' : ''), d.email || (!example ? 'Email' : ''), d.website || (!example ? 'LinkedIn / Portfolio' : ''), d.location || (!example ? 'Location' : '')].filter(Boolean).map(x => `<span>${escape(x)}</span>`).join('')}</div></header><div class="cv-columns"><div class="cv-main">${summary}${experience}${['single','centered','banner'].includes(template.layout) ? education : ''}</div><aside class="cv-side">${achievements}${skills}${['double','sidebar'].includes(template.layout) ? education : ''}${courses}${languages}</aside></div></article>`;
    },
}));
