import { signInTestUser, removeTestUser } from './browser-auth.mjs';
import fs from 'node:fs';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const snapshot = locator => locator.evaluate(paper => {
    const selectors = ['', '.cv-header', '.cv-header h2', '.cv-contact', '.cv-columns', '.cv-side', '.cv-section', '.cv-section h3', '.cv-entry'];
    const properties = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'color', 'backgroundColor', 'padding', 'margin', 'display', 'gridTemplateColumns', 'gap', 'borderBottomWidth', 'textAlign'];
    return {
        html: paper.innerHTML,
        width: paper.offsetWidth,
        height: paper.offsetHeight,
        styles: selectors.map(selector => {
            const node = selector ? paper.querySelector(selector) : paper;
            if (!node) return null;
            const css = getComputedStyle(node);
            return { ...Object.fromEntries(properties.map(property => [property, css[property]])), geometry: [node.offsetLeft, node.offsetTop, node.offsetWidth, node.offsetHeight] };
        }),
    };
});
async function settle() {
    await page.evaluate(async () => {
        await document.fonts.ready;
        const builder = window.Alpine.$data(document.querySelector('[x-data]'));
        await builder.$nextTick();
        builder.schedulePageFit();
        await new Promise(requestAnimationFrame);
        await new Promise(requestAnimationFrame);
    });
}
fs.mkdirSync('storage/app/previews/template-parity', { recursive: true });
let auditFixture;
try {
    auditFixture = await signInTestUser(page, process.env.APP_TEST_URL || 'http://127.0.0.1:8000');
    await page.goto((process.env.APP_TEST_URL || 'http://127.0.0.1:8000') + '/get-started?new=1');
    await page.getByRole('button', { name: /No, start from scratch/ }).click();
    await page.getByRole('button', { name: 'Skip this step', exact: true }).click();
    const templates = await page.evaluate(() => {
        const builder = window.Alpine.$data(document.querySelector('[x-data]'));
        const data = builder.atsExample(builder.templates.find(template => template.id === 'ats-professional'));
        data.achievements = [{ title: 'Team award', description: 'Recognised for improving customer service.' }];
        data.languages = [{ name: 'English', level: 'Fluent' }];
        data.competencies = 'Project planning';
        builder.data = { ...builder.data, ...data };
        return builder.templates;
    });
    const structures = new Set();
    for (const template of templates) {
        await page.setViewportSize({ width: 1440, height: 1100 });
        await page.evaluate(id => { const builder = window.Alpine.$data(document.querySelector('[x-data]')); builder.data.template = id; builder.step = 3; }, template.id);
        await settle();
        const card = page.getByRole('button', { name: `Choose ${template.name} template`, exact: true });
        await expect.poll(() => card.evaluate(card => {
            const stage = card.querySelector('.cv-stage');
            const css = getComputedStyle(card);
            const available = card.clientWidth - parseFloat(css.paddingLeft) - parseFloat(css.paddingRight);
            return Math.abs(stage.getBoundingClientRect().width - available) < 2;
        })).toBe(true);
        const expected = await snapshot(card.locator('.cv-paper'));
        if (template.category !== 'ATS Pass') {
            const structure = await card.locator('.cv-paper').evaluate(paper => ['.cv-lead', '.cv-main', '.cv-side', '.cv-footer'].map(selector => Array.from(paper.querySelector(selector)?.querySelectorAll('[data-section]') || []).map(section => section.dataset.section)));
            const sections = structure.flat();
            expect(sections.length).toBe(11);
            expect(new Set(sections).size).toBe(11);
            structures.add(JSON.stringify(structure));
        }
        if (template.id === 'elegant') {
            await expect(card.locator('.cv-main [data-section=education]')).toHaveCount(1);
            await expect(card.locator('.cv-main [data-section=references]')).toHaveCount(1);
            await expect(card.locator('.cv-side [data-section=education], .cv-side [data-section=references]')).toHaveCount(0);
        }

        await page.evaluate(() => { window.Alpine.$data(document.querySelector('[x-data]')).step = 4; });
        await settle();
        const paper = page.locator('.editor-paper-wrap .cv-paper');
        assert.deepEqual(await snapshot(paper), expected, template.id + ': card and editor layout differ');
        await page.setViewportSize({ width: 390, height: 844 });
        await settle();
        assert.deepEqual(await snapshot(paper), expected, template.id + ': mobile layout differs');
        await page.emulateMedia({ media: 'print' });
        await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
        assert.deepEqual(await snapshot(paper), expected, template.id + ': printed layout differs');
        const pdf = await page.pdf({ path: `storage/app/previews/template-parity/${template.id}.pdf`, printBackground: true, preferCSSPageSize: true });
        expect((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length).toBe(1);
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        await page.emulateMedia({ media: null });
    }
    expect(structures.size).toBe(19);
    expect(templates.some(template => template.id === 'slate')).toBe(false);
    expect(errors).toEqual([]);
    console.log('All 22 designs match between template cards, desktop editor, mobile editor, and one-page printed PDFs.');
} finally { removeTestUser(auditFixture);
    await browser.close();
}
