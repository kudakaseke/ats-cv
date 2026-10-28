import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

// A real worker must not consume the disposable job before mock completion.
if (process.platform === 'win32') {
    const active = execFileSync('powershell', ['-NoProfile', '-Command', "@(Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match 'queue:work ai' }).Count"], { encoding: 'utf8' }).trim();
    if (Number(active) > 0) throw new Error('Stop the real AI worker before running this mocked browser check.');
}
const input = JSON.parse(execFileSync('php', ['scripts/master-v2-browser-fixture.php', 'input'], { encoding: 'utf8' }));
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/workspace/checker`);
    await page.getByLabel('Job description', { exact: true }).fill(input.job);
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await page.locator('.checker-paste summary').click();
    await page.locator('#checker-resume').fill(input.resume);
    await page.getByRole('button', { name: 'Next: ATS scan' }).click();
    const scanAccepted = page.waitForResponse(response => response.url().endsWith('/checker/scan'));
    await page.getByRole('button', { name: 'ATS Scan', exact: true }).click();
    const scanResponse = await scanAccepted;
    expect(scanResponse.status()).toBe(202);
    const scanPending = await scanResponse.json();
    const scanned = JSON.parse(execFileSync('php', ['scripts/master-v2-browser-fixture.php', 'complete', fixture.email, String(scanPending.operation_id)], { encoding: 'utf8' }));
    expect(scanned.paid_api_calls).toBe(0);
    expect(scanned.mocked_stages).toBe(1);
    const dialog = page.locator('.checker-result-dialog');
    await expect(dialog.getByRole('heading', { name: 'Your ATS scan result' })).toBeVisible();
    await expect(dialog.getByText('Suggestions to improve your CV', { exact: true })).toBeVisible();
    const queued = page.waitForResponse(response => response.url().endsWith('/checker/rewrite'));
    await dialog.getByRole('button', { name: 'Fix Resume', exact: true }).click();
    const accepted = await queued;
    expect(accepted.status()).toBe(202);
    const pending = await accepted.json();
    await expect(page.getByRole('heading', { name: 'Creating your tailored CV' })).toBeVisible();
    const completed = JSON.parse(execFileSync('php', ['scripts/master-v2-browser-fixture.php', 'complete', fixture.email, String(pending.operation_id)], { encoding: 'utf8' }));
    expect(completed.paid_api_calls).toBe(0);
    await expect(page).toHaveURL(/get-started/, { timeout: 15000 });
    await expect(page.getByText('What AI changed', { exact: true })).toBeVisible();
    await expect(page.locator('.ai-change-report')).toContainText('Before:');
    await expect(page.locator('.ai-change-report')).toContainText('After:');
    await expect(page.locator('.ai-change-report')).toContainText('Source evidence:');
    await expect(page.getByText('Remaining Warnings', { exact: true })).toBeVisible();
    await expect(page.locator('.checker-report-warning-content')).toContainText('AWS certification');
    await expect(page.locator('.verified-keyword-coverage')).toContainText('100%');
    expect(errors).toEqual([]);
    fs.mkdirSync('storage/app/previews', { recursive: true });
    await page.screenshot({ path: 'storage/app/previews/master-v2-report.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    console.log(JSON.stringify({ passed: true, stages: 3, paidApiCalls: 0, builderReportVisible: true, genuineGapVisible: true, javascriptErrors: errors }));
} finally {
    await browser.close();
    removeTestUser(fixture);
}
