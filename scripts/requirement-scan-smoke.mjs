import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const job = 'Qualifications\nNational Diploma in Commerce\nSkills\nIdentifying Sales Opportunities, Oral Communications\nCompetencies\nTeamwork\nExperience\n5 years of sales experience';
const resume = 'Alex Example\nEducation\nBachelor of Commerce\nSkills\nOral Communications\nCompetencies\nTeamwork\nExperience\nPosition: Sales Assistant\nPeriod: Jan 2023 - Dec 2024\nCertifications\nOral Communications Certificate';
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
    await expect(dialog.locator('.checker-dialog-score')).toHaveText('77/100');
    for (const category of ['Qualifications', 'Skills', 'Competencies']) await expect(dialog.getByRole('heading', { name: category, exact: true })).toBeVisible();
    await expect(dialog.locator('.checker-requirement-groups')).toContainText('Identifying Sales Opportunities');
    await expect(dialog.locator('.checker-green-flags')).toContainText('Higher relevant qualification');
    await expect(dialog.locator('.checker-green-flags')).toContainText('+2 certificate bonus points');
    await expect(dialog.locator('.checker-experience-warning')).toContainText('approximately 2 years');
    await expect(dialog.getByText('Matched keywords / total detected keywords × 100. This is a keyword match, not a guaranteed ATS pass.', { exact: true })).toHaveCount(0);
    await dialog.getByText('Add skills you have that are missing from your CV', { exact: true }).click();
    await expect(dialog.getByRole('checkbox', { name: 'Identifying Sales Opportunities', exact: true })).toBeVisible();
    await expect(dialog.getByRole('checkbox', { name: 'National Diploma in Commerce', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
    console.log('PASS: three requirement categories, whole skill phrases, 75% requirement coverage plus 2 certificate points, experience warning and removed statement. No paid AI call.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
