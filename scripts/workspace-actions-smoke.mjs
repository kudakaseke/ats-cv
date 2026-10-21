import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
let fixture;
try {
    fixture = await signInTestUser(page, base);
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const response = await page.request.post(`${base}/get-started/draft`, {
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        data: { step: 4, data: { template: 'elegant', name: 'Deletion Audit', experience: [], education: [], achievements: [], courses: [], languages: [] } },
    });
    expect(response.ok()).toBeTruthy();
    await page.goto(`${base}/get-started/finish`);
    await page.goto(`${base}/workspace/resumes`);
    await expect(page.getByText('Deletion Audit CV', { exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.dismiss());
    await page.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByText('Deletion Audit CV', { exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'No saved CVs yet' })).toBeVisible();
    await page.goto(`${base}/subscription`);
    for (const plan of ['monthly', 'annual']) {
        await expect(page.locator(`#plan-${plan}`)).toBeVisible();
    }
    if (process.argv.includes('--live-ai')) {
    const scan = await page.request.post(`${base}/checker/scan`, {
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        data: { job_description: 'PHP SQL developer', resume_text: 'PHP developer' },
    });
    expect(scan.ok()).toBeTruthy();
    await page.goto(`${base}/workspace/history`);
    await page.getByRole('link', { name: 'View result', exact: true }).click();
    await expect(page.getByText('PHP SQL developer', { exact: true })).toBeVisible();
    } else {
        await page.goto(`${base}/workspace/history`);
        await expect(page.getByRole('heading', { name: 'Saved checks', exact: true })).toBeVisible();
    }
    console.log('PASS: saved CV delete cancellation/confirmation, subscription cards and history. Live AI scan only runs with --live-ai.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
