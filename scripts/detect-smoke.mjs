import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/dashboard`);
    expect(await page.locator('.dashboard-action-card h2').allTextContents()).toEqual(['Job-to-CV ATS Detector', 'Create CV', 'View Templates', 'Plan', 'Settings', 'ATS Checker']);
    await page.locator('.dashboard-action-card').filter({ has: page.getByRole('heading', { name: 'ATS Checker', exact: true }) }).click();
    await expect(page.getByRole('heading', { name: 'ATS Checker', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Check ATS readiness' }).click();
    await expect(page.getByRole('alert')).toHaveText('Upload your CV or paste its text first.');
    const text = `Alex Example\nalex@example.com | +27 71 123 4567\nWork Experience\nAnalyst, Example Ltd, 2020 - Present\n${'Delivered accurate financial reports and supported monthly business reporting. '.repeat(12)}\nEducation\nDegree, Example College, 2019\nTechnical Skills\nReporting, Excel`;
    await page.locator('#detect-file').setInputFiles({ name: 'example.txt', mimeType: 'text/plain', buffer: Buffer.from(text) });
    await expect(page.locator('#detect-resume')).toHaveValue(text);
    await page.getByRole('button', { name: 'Check ATS readiness' }).click();
    await expect(page.getByRole('heading', { name: 'Your CV passes the basic text and structure checks.' })).toBeVisible();
    await expect(page.locator('[x-ref="detectResults"] li')).toHaveCount(7);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('link', { name: 'Compare with a job description' }).click();
    await expect(page.getByRole('heading', { name: 'Job-to-CV ATS Detector', exact: true })).toBeVisible();
    await expect(page.locator('#checker-job')).toBeVisible();
    expect(errors).toEqual([]);
    console.log('PASS: dashboard card order, ATS Checker upload/results, mobile layout and separate job-description scanner; no AI calls.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
