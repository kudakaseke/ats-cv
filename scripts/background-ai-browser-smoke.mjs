import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    fixture = JSON.parse(execFileSync('php', ['scripts/browser-fixture.php', 'create'], { encoding: 'utf8' }));
    await page.goto(`${base}/login`);
    await page.getByLabel('Email', { exact: true }).fill(fixture.email);
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto(`${base}/workspace/checker`);
    await page.getByLabel('Job description', { exact: true }).fill('PHP developer. Required skills: PHP, Laravel, MySQL.');
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await page.locator('.checker-paste summary').click();
    await page.getByLabel('Resume text — review or paste your CV').fill('Alex Example\nPHP Developer\nSkills\nPHP, Laravel, MySQL');
    await page.getByRole('button', { name: 'Next: ATS scan' }).click();
    let submits = 0;
    let polls = 0;
    page.on('request', request => {
        if (request.url().endsWith('/checker/scan') && request.method() === 'POST') submits++;
        if (request.url().includes('/checker/operations/')) polls++;
    });
    const started = Date.now();
    const accepted = page.waitForResponse(response => response.url().endsWith('/checker/scan'));
    await page.getByRole('button', { name: 'ATS Scan', exact: true }).click();
    const response = await accepted;
    expect(response.status()).toBe(202);
    expect(Date.now() - started).toBeLessThan(10000);
    const operation = await response.json();
    await expect(page.getByRole('heading', { name: 'Scanning your resume' })).toBeVisible();
    await page.waitForResponse(response => response.url().includes('/checker/operations/') && response.status() === 202);
    const completed = JSON.parse(execFileSync('php', ['scripts/background-ai-fixture.php', fixture.email, String(operation.operation_id)], { encoding: 'utf8' }));
    expect(completed.provider_calls).toBe(0);
    await expect(page.getByRole('heading', { name: 'Your ATS scan result' })).toBeVisible({ timeout: 15000 });
    await expect(page.locator('.checker-processing')).toBeHidden();
    expect(submits).toBe(1);
    expect(polls).toBeGreaterThanOrEqual(2);
    expect(errors).toEqual([]);
    fs.mkdirSync('storage/app/previews', { recursive: true });
    await page.screenshot({ path: 'storage/app/previews/kimi-background-scan.png', fullPage: true });
    console.log(JSON.stringify({ passed: true, scanSubmissions: submits, statusChecks: polls, paidApiCalls: 0 }));
} finally {
    await browser.close();
    if (fixture) execFileSync('php', ['scripts/browser-fixture.php', 'delete', fixture.email]);
}
