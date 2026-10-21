import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const job = 'Requirements\nAt least 5 years of finance experience. Excel, reporting, SQL.';
const resume = 'Alex Example\nExperience\nPosition: Analyst\nCompany: Example\nPeriod: Jan 2023 - Dec 2024\nPrepared Excel reports.\nSkills\nExcel, reporting';
const result = JSON.parse(execFileSync('php', ['-r', "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $data=json_decode(stream_get_contents(STDIN),true); echo json_encode(app(App\\Services\\CvKeywordMatcher::class)->compare($data['job'],$data['resume']));"], { input: JSON.stringify({ job, resume }), encoding: 'utf8' }));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
let fixture;
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    fixture = await signInTestUser(page, base);
    await page.route('**/checker/scan', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify(result) }));
    await page.goto(`${base}/workspace/checker`);
    await page.getByLabel('Job description', { exact: true }).fill(job);
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await page.locator('.checker-paste summary').click();
    await page.locator('#checker-resume').fill(resume);
    await page.getByRole('button', { name: 'Next: ATS scan' }).click();
    await page.getByRole('button', { name: 'ATS Scan', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.checker-dialog-score')).toHaveText(result.keyword_score + '/100');
    await expect(dialog.getByRole('heading', { name: 'Experience gap to review' })).toBeVisible();
    await expect(dialog.locator('.checker-experience-warning')).toContainText('approximately 2 years');
    expect(await dialog.evaluate(el => el.getBoundingClientRect().width)).toBeGreaterThan(900);
    await dialog.getByText('Add skills you have that are missing from your CV', { exact: true }).click();
    await dialog.getByRole('checkbox', { name: 'SQL', exact: true }).check();
    let confirmed;
    await page.route('**/checker/rewrite', async route => {
        confirmed = route.request().postDataJSON().confirmed_keywords;
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Test response: no AI request made.' }) });
    });
    await dialog.getByRole('button', { name: 'Fix Resume', exact: true }).click();
    await expect(dialog.getByText('Test response: no AI request made.', { exact: true })).toBeVisible();
    expect(confirmed).toEqual(['SQL']);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await dialog.evaluate(el => el.getBoundingClientRect().width)).toBeLessThanOrEqual(358);
    await page.getByRole('button', { name: 'Close scan results' }).click();
    await page.getByText('How is the score calculated?', { exact: true }).click();
    await expect(page.getByText(/Whole requirement phrases are grouped/)).toBeVisible();
    expect(errors).toEqual([]);
    console.log('PASS: rectangular desktop/mobile results, keyword score explanation, experience warning, and confirmed skill sent for revamp. API mocked; no paid AI call.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
