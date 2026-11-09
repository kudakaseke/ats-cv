import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

// Always deterministic: no --ai flag can enable a paid provider request.
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(20000);
const errors = [];
const responses = [];
page.on('response', response => {
    const url = new URL(response.url());
    if (url.origin === new URL(base).origin && !url.pathname.startsWith('/build/')) responses.push({ path: url.pathname, status: response.status() });
});
page.on('pageerror', error => errors.push(error.message));
const original = 'Alex Morgan\nalex@example.invalid\nSkills\nPHP, SQL\nEducation\nDiploma in Computing\nExample College';
const structured = { name: 'Alex Morgan', role: '', phone: '', location: '', website: '', references: '', core_strengths: [], competencies: [], email: 'alex@example.invalid', summary: 'Builds applications using PHP and SQL.', skills: ['PHP', 'SQL'], experience: [], education: [{ degree: 'Diploma in Computing', school: 'Example College', period: '2020', location: '' }], achievements: [], courses: [], languages: [], custom_sections: [] };
let fixture, scanCalls = 0, rewriteCalls = 0, scanId, failedRewriteId;
const billedScans = new Set();
try {
    fixture = await signInTestUser(page, base);
    await page.route('**/checker/scan', async route => {
        scanCalls++;
        const input = route.request().postDataJSON();
        expect(input.resume_text).toBe(original);
        billedScans.add(input.operation_id);
        if (!scanId) scanId = input.operation_id;
        else expect(input.operation_id).toBe(scanId);
        // Simulate a lost completed result followed by an unreadable cached response.
        if (scanCalls === 2) return route.fulfill({ status: 200, contentType: 'application/json', body: '{' });
        if (scanCalls === 1) return route.fulfill({ status: 503, json: { message: 'Scan temporarily unavailable.' } });
        return route.fulfill({ json: { keyword_score: 67, compatibility_score: 60, score: 67, matched: ['PHP', 'SQL'], missing: ['JavaScript'], total: 3, credits_remaining: 10 } });
    });
    await page.route('**/checker/rewrite', async route => {
        rewriteCalls++;
        expect(route.request().postDataJSON().confirmed_keywords).toEqual([]);
        const id = route.request().postDataJSON().operation_id;
        if (rewriteCalls === 1) {
            failedRewriteId = id;
            return route.fulfill({ status: 503, json: { message: 'AI temporarily unavailable. Your CV is unchanged.', operation_failed: true, operation_id: id } });
        }
        expect(id).not.toBe(failedRewriteId);
        return route.fulfill({ json: { structured_resume: structured, rewritten_resume: original, changes: [], unsupported_keywords: ['JavaScript'] } });
    });
    await page.goto(`${base}/workspace/checker`);
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByLabel('Job description', { exact: true }).fill('PHP SQL JavaScript');
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await page.getByLabel('Resume file', { exact: true }).setInputFiles({ name: 'resume.txt', mimeType: 'text/plain', buffer: Buffer.from(original) });
    await page.getByRole('button', { name: 'Next: ATS scan' }).click();
    await page.getByRole('button', { name: 'ATS Scan', exact: true }).click();
    await expect(page.getByRole('heading', { name: "We couldn't complete your scan" })).toBeVisible();
    await page.getByRole('button', { name: 'Retry ATS scan' }).click();
    await expect(page.locator('dialog[open] [role=alert]:visible')).toContainText('unexpected response');
    await page.getByRole('button', { name: 'Retry ATS scan' }).click();
    const dialog = page.getByRole('dialog', { name: 'Your ATS scan result' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.checker-score-card.wording strong')).toHaveText('67');
    await expect(dialog.locator('.checker-score-card.compatibility strong')).toHaveText('60');
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await dialog.getByRole('button', { name: 'Close scan results' }).click();
    await page.getByRole('button', { name: 'Fix Resume', exact: true }).click();
    await expect(page.locator('dialog[open] [role=alert]:visible')).toHaveText('AI temporarily unavailable. Your CV is unchanged.');
    await page.getByRole('button', { name: 'Close scan results' }).click();
    await page.getByRole('button', { name: 'Fix Resume', exact: true }).click();
    await expect(page).toHaveURL(/get-started/, { timeout: 15000 });
    await expect(page.locator('.editor-paper-wrap')).toContainText('Alex Morgan');
    expect(scanCalls).toBe(3);
    expect(billedScans.size).toBe(1);
    expect(rewriteCalls).toBe(2);
    expect(errors).toEqual([]);
    console.log('PASS: scanner validation, upload, ambiguous/malformed response retries with one operation ID, two scores, mobile results and confirmed-refund rewrite recovery; providers mocked.');
} catch (error) {
    console.error(JSON.stringify({ url: page.url(), responses, errors }));
    throw error;
} finally {
    await browser.close();
    removeTestUser(fixture);
}
