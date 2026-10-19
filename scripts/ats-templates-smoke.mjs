import { signInTestUser, removeTestUser } from './browser-auth.mjs';
import fs from 'node:fs';
import { chromium, expect } from '@playwright/test';

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
fs.mkdirSync('storage/app/previews', { recursive: true });
let auditFixture;
try {
    auditFixture = await signInTestUser(page, process.env.APP_TEST_URL || 'http://127.0.0.1:8000');
    await page.goto((process.env.APP_TEST_URL || 'http://127.0.0.1:8000') + '/get-started?new=1');
    await page.getByRole('button', { name: /No, start from scratch/ }).click();
    await page.getByRole('button', { name: 'Skip this step', exact: true }).click();
    await page.getByRole('button', { name: 'ATS Pass', exact: true }).click();
    await page.locator('.template-group').screenshot({ path: 'storage/app/previews/ats-reference-corrected.png' });
    for (const [id, firstSection] of [['ats-serif', 'Experience'], ['ats-compact', 'Experience'], ['ats-professional', 'Professional summary']]) {
        await page.evaluate(id => {
            const builder = window.Alpine.$data(document.querySelector('[x-data]'));
            const template = builder.templates.find(template => template.id === id);
            builder.data = { ...builder.data, ...builder.atsExample(template), template: id };
            builder.step = 4;
        }, id);
        const paper = page.locator('.editor-paper-wrap .cv-ats');
        await expect(paper).toBeVisible();
        await expect(paper.locator('.cv-section h3').first()).toHaveText(firstSection);
        await expect(paper.locator('.cv-section h3').first()).toHaveCSS('border-top-width', '0px');
        await expect(paper.locator('.cv-section h3').first()).toHaveCSS('border-left-width', '0px');
        const date = paper.locator('[data-section=experience] .cv-entry').first();
        await expect(date.locator(id === 'ats-compact' ? '.ats-entry-subheading > span:last-child' : '.ats-entry-heading > span')).toHaveText('Apr 2017 - Present');
        if (id === 'ats-serif') {
            await expect(paper).toHaveCSS('font-family', '"Times New Roman", Times, serif');
            await expect(paper.locator('.cv-header')).toHaveCSS('text-align', /left|start/);
        } else {
            await expect(paper.locator('.cv-header')).toHaveCSS('text-align', 'center');
        }
        if (id === 'ats-professional') {
            await expect(paper.locator('.cv-section h3')).toHaveText(['Professional summary', 'Professional experience', 'Education', 'Certification', 'Technical skills', 'Projects', 'Core strengths', 'References']);
        }
        await page.mouse.move(0, 0);
        await page.locator('.editor-paper-wrap').screenshot({ path: `storage/app/previews/${id}-corrected.png` });
        await page.emulateMedia({ media: 'print' });
        if (id === 'ats-professional') await expect(paper).toHaveCSS('font-size', '12px');
        await expect(paper).not.toContainText('LinkedIn / Portfolio');
        await page.pdf({ path: `storage/app/previews/${id}-corrected.pdf`, format: 'A4', printBackground: true, preferCSSPageSize: true });
        await page.emulateMedia({ media: 'screen' });
    }
    expect(errors).toEqual([]);
    console.log('ATS reference checks passed: distinct fonts, header alignment, date placement, section order, bottom rules, and print styles.');
} finally { removeTestUser(auditFixture);
    await browser.close();
}

