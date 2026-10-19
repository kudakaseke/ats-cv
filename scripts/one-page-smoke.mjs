import { signInTestUser, removeTestUser } from './browser-auth.mjs';
import fs from 'node:fs';
import { chromium, expect } from '@playwright/test';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
fs.mkdirSync('storage/app/previews/one-page', { recursive: true });
let auditFixture;
try {
    auditFixture = await signInTestUser(page, process.env.APP_TEST_URL || 'http://127.0.0.1:8000');
    await page.goto((process.env.APP_TEST_URL || 'http://127.0.0.1:8000') + '/get-started?new=1');
    await page.getByRole('button', { name: /No, start from scratch/ }).click();
    await page.getByRole('button', { name: 'Skip this step', exact: true }).click();
    await page.getByRole('button', { name: 'Choose ATS Professional template', exact: true }).click();
    const templates = await page.evaluate(() => window.Alpine.$data(document.querySelector('[x-data]')).templates.map(template => template.id));
    for (const id of templates) {
        await page.emulateMedia({ media: 'screen' });
        await page.evaluate(id => {
            const builder = window.Alpine.$data(document.querySelector('[x-data]'));
            const data = builder.atsExample(builder.templates.find(template => template.id === 'ats-professional'));
            const job = data.experience[0];
            data.experience = Array.from({ length: 8 }, (_, index) => ({ ...job, title: `Position ${index + 1}`, company: `Company ${index + 1}`, description: job.description + '\nDelivered complete reports and supported successful project delivery across the business.' }));
            data.references = 'FINAL CONTENT MARKER: Available on request.';
            builder.data = { ...builder.data, ...data, template: id };
            builder.schedulePageFit();
        }, id);
        await expect(page.locator('.editor-paper-wrap')).toContainText('FINAL CONTENT MARKER');
        await expect.poll(() => page.locator('.editor-paper-wrap').evaluate(wrap => {
            const paper = wrap.querySelector('.cv-paper');
            return paper.getBoundingClientRect().bottom <= wrap.getBoundingClientRect().bottom + 1;
        })).toBe(true);
        // Let PDF printing switch to print media, just as the browser print dialog does.
        await page.emulateMedia({ media: null });
        const nativePdf = await page.pdf({ path: `storage/app/previews/one-page/${id}-native.pdf`, printBackground: true, preferCSSPageSize: true });
        expect((nativePdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, id + ' native print page count').toBe(1);
        await page.emulateMedia({ media: 'print' });
        await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
        const fits = await page.locator('.editor-paper-wrap').evaluate(wrap => {
            const paper = wrap.querySelector('.cv-paper');
            return { fits: paper.getBoundingClientRect().bottom <= wrap.getBoundingClientRect().bottom + 1, scale: Number(wrap.style.getPropertyValue('--cv-fit-scale')) };
        });
        expect(fits.fits, id + ' print content stays within page').toBe(true);
        expect(fits.scale).toBeGreaterThan(0);
        expect(fits.scale).toBeLessThanOrEqual(1);
        const pdf = await page.pdf({ path: `storage/app/previews/one-page/${id}.pdf`, printBackground: true, preferCSSPageSize: true });
        expect((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, id + ' PDF page count').toBe(1);
    }
    expect(errors).toEqual([]);
    console.log(`All ${templates.length} designs fit eight jobs and the final reference on one PDF page without clipping.`);
} finally { removeTestUser(auditFixture);
    await browser.close();
}
