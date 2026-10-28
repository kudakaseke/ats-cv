import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    await page.route(/\/checker\/(scan|rewrite)$/, route => route.abort());
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/get-started?new=1`);
    await page.getByRole('button', { name: /No, start from scratch/ }).click();
    await page.getByRole('button', { name: 'Skip this step', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Elegant template', exact: true }).click();
    await page.evaluate(() => {
        const builder = Alpine.$data(document.querySelector('[x-data]'));
        builder.data = { ...builder.data, ...builder.atsExample(builder.templates.find(template => template.id === 'ats-professional')),
            template: 'elegant', name: 'Export Auditor', email: 'audit@example.invalid', skills: 'PHP, Laravel, SQL' };
    });
    await page.getByRole('button', { name: 'Review CV', exact: true }).first().click();
    fs.mkdirSync('storage/app/previews', { recursive: true });
    for (const [label, extension] of [['PDF', 'pdf'], ['Word', 'docx']]) {
        await page.getByRole('button', { name: 'Download', exact: true }).click();
        const response = page.waitForResponse(item => item.url().endsWith('/get-started/export'), { timeout: 90000 });
        const download = page.waitForEvent('download', { timeout: 90000 });
        download.catch(() => {});
        await page.getByRole('button', { name: new RegExp(`^${label}`) }).click();
        expect((await response).status()).toBe(200);
        await (await download).saveAs(`storage/app/previews/sol-audit-export.${extension}`);
    }
    const checked = execFileSync(path.resolve('.venv-ats/Scripts/python.exe'), ['-c', `import pymupdf,zipfile
with pymupdf.open('storage/app/previews/sol-audit-export.pdf') as pdf:
 text=''.join(page.get_text() for page in pdf); assert 'EXPORT AUDITOR' in text.upper() and 'SQL' in text
with zipfile.ZipFile('storage/app/previews/sol-audit-export.docx') as doc:
 text=doc.read('word/document.xml').decode(); assert 'EXPORT AUDITOR' in text.upper() and 'SQL' in text
print('PDF and editable DOCX content verified')`], { encoding: 'utf8' });
    expect(checked).toContain('verified');
    expect(errors).toEqual([]);
    console.log(JSON.stringify({ passed: true, formats: ['PDF', 'DOCX'], content_verified: true, paid_api_calls: 0, javascript_errors: errors }));
} finally {
    await browser.close();
    removeTestUser(fixture);
}
