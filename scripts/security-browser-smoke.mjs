import { chromium, expect } from '@playwright/test';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome' });
try {
    const page = await browser.newPage();
    await page.route('https://fonts.googleapis.com/**', route => route.abort());
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/get-started?templates=1');
    await page.waitForFunction(() => window.Alpine && document.querySelector('[x-data^="cvBuilder"]')?._x_dataStack);
    const tested = await page.evaluate(async () => {
        const builder = window.Alpine.$data(document.querySelector('[x-data^="cvBuilder"]'));
        const payload = '</p><img src="x" onerror="window.securityProbe=1"><div x-init="window.securityProbe=1">';
        window.securityProbe = 0;
        builder.data = {
            ...builder.data, name: payload, role: payload, phone: payload, location: payload,
            website: payload, email: 'candidate@example.invalid', summary: payload, skills: payload,
            competencies: payload, core_strengths: payload, references: payload,
            section_titles: { experience: payload },
            experience: [{ title: payload, company: payload, location: payload, period: payload, description: payload }],
            education: [{ degree: payload, school: payload, grade: payload, period: payload, location: payload }],
            achievements: [{ title: payload, description: payload }],
            courses: [{ title: payload, institution: payload, period: payload }],
            languages: [{ name: payload, level: 'Fluent' }],
            custom_sections: [{ title: payload, content: payload }],
        };
        const container = document.createElement('div');
        document.body.append(container);
        let count = 0;
        try {
            for (const template of builder.templates) {
                for (const markup of [builder.preview(template), builder.editablePreview(template), builder.reviewPreview(template)]) {
                    container.innerHTML = markup;
                    if (container.querySelector('img[onerror], [x-init], script, iframe, object, embed')) {
                        throw new Error('Injected markup in ' + template.id);
                    }
                    count++;
                }
            }
            await new Promise(resolve => setTimeout(resolve, 100));
            if (window.securityProbe !== 0) throw new Error('Injected code executed');
        } finally {
            container.remove();
        }
        return count;
    });
    expect(tested).toBeGreaterThan(100);
    expect(errors).toEqual([]);
    console.log(`PASS: ${tested} template/editor/review combinations escape malicious CV fields; no injected code executes.`);
} finally {
    await browser.close();
}
