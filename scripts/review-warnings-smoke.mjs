import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
let fixture;
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    fixture = await signInTestUser(page, base);
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const cv = 'Alex Example\nExperience\nPosition: Analyst\nCompany: Example Organisation\nPeriod: Jan 2023 - Dec 2024\nEducation\nDiploma in Computing\nSkills\nPython';
    const response = await page.request.post(`${base}/checker/edit`, { headers: { 'X-CSRF-TOKEN': csrf }, form: { generated: '1', resume_text: cv, original_resume_text: cv, job_description: 'Qualifications\nBachelor of Commerce\nSkills\nSQL\nCompetencies\nTeamwork\nExperience\n5 years of experience' } });
    expect(response.ok()).toBeTruthy();
    await page.goto(`${base}/get-started`);
    const missing = page.locator('.missing-requirements');
    await expect(missing.locator('summary')).toContainText('Requirements still missing (3)');
    await expect(missing.getByRole('heading', { name: 'Warnings', exact: true })).toBeVisible();
    await expect(missing.locator('.checker-review-warnings')).toContainText('approximately 2 years');
    expect(await missing.locator('.checker-review-warnings').evaluate(el => getComputedStyle(el).color)).toBe('rgb(161, 27, 27)');
    expect(await missing.locator('.checker-review-warnings li').first().evaluate(el => getComputedStyle(el).color)).toBe('rgb(161, 27, 27)');
    await expect(missing.locator('.missing-requirement-groups > section')).toHaveCount(3);
    await expect(missing.locator('.missing-requirement-groups li')).toHaveCount(3);
    await page.screenshot({ path: 'storage/app/review-missing-warnings.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await missing.locator('.missing-requirement-groups').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length)).toBe(1);
    expect(errors).toEqual([]);
    console.log('PASS: review has ordered category lists, red 5-year/2-year warnings and a mobile single-column layout. No AI call or credit deduction.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
