import { signInTestUser } from './browser-auth.mjs';
import { chromium, expect } from '@playwright/test';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

expect.configure({ timeout: 20000 });
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
fs.mkdirSync('storage/app/previews', { recursive: true });
let fixture;
try {
    await page.goto(base);
    await page.locator('.reference-start').click();
    await expect(page).toHaveURL(/login/);
    fixture = await signInTestUser(page, base);
    await expect(page).toHaveURL(/get-started/);
    await expect(page.getByRole('heading', { name: /Do you have an existing CV/ })).toBeVisible();
    await page.screenshot({ path: 'storage/app/previews/builder-start.png', fullPage: true });
    await page.getByRole('button', { name: /No, start from scratch/ }).click();
    await Promise.all([
        page.waitForResponse(r => r.url().endsWith('/get-started/draft') && r.request().method() === 'POST' && r.request().postDataJSON().step === 3),
        page.getByRole('button', { name: 'Skip this step', exact: true }).click(),
    ]);
    await page.reload();
    await expect(page.locator('.template-card')).toHaveCount(22);
    await expect(page.locator('.template-card').first()).toBeVisible();
    await page.locator('.builder-gallery').getByRole('button', { name: '\u2190 Back', exact: true }).click();
    await page.getByLabel('Target position').fill('Software Engineer');
    await page.getByRole('button', { name: 'Next \u2192', exact: true }).click();
    await expect(page.locator('.template-card')).toHaveCount(22);
    await page.screenshot({ path: 'storage/app/previews/builder-templates.png', fullPage: true });
    await expect(page.locator('.template-group-heading > span')).toHaveText(['Modern', 'Simple', 'Traditional', 'Creative', 'ATS Pass']);
    const categorizedNames = [];
    for (const [category, count] of [['Modern', 5], ['Simple', 5], ['Traditional', 5], ['Creative', 4], ['ATS Pass', 3]]) {
        await page.getByRole('button', { name: category, exact: true }).click();
        await expect(page.locator('.template-group')).toHaveCount(1);
        await expect(page.locator('.template-card')).toHaveCount(count);
        await expect(page.locator('.template-group-heading > span')).toHaveText([category]);
        categorizedNames.push(...await page.locator('.template-card').evaluateAll(cards => cards.map(card => card.getAttribute('aria-label'))));
    }
    expect(new Set(categorizedNames).size).toBe(22);
    for (const name of ['ATS Classic', 'ATS Compact', 'ATS Professional']) {
        await page.getByRole('button', { name: 'Choose ' + name + ' template', exact: true }).click();
        await expect(page.locator('.editor-paper-wrap .cv-ats')).toBeVisible();
        await expect(page.locator('.editor-paper-wrap .cv-placeholder')).toHaveCount(0);
        await page.getByRole('button', { name: 'Change template', exact: true }).click();
    }
    await page.getByRole('button', { name: 'Traditional', exact: true }).click();
    await expect(page.locator('.template-card')).toHaveCount(5);
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Double Column template' }).click();
    await page.getByLabel('Full name', { exact: true }).fill('Browser Builder');
    await page.getByLabel('Email', { exact: true }).fill('builder@example.com');
    await page.getByLabel('Phone', { exact: true }).fill('+27 71 234 5678');
    await page.getByLabel('Edit section').selectOption('summary');
    await page.getByLabel('Professional summary').fill('An experienced engineer building practical tools for people.');
    await expect(page.locator('.editor-paper-wrap')).toContainText('An experienced engineer');
    await page.getByLabel('Edit section').selectOption('experience');
    await page.getByLabel('Job title', { exact: true }).fill('Software Engineer');
    await page.getByLabel('Company', { exact: true }).fill('Example Company');
    const periodEditor = page.locator(".editor-fields[x-show=\"tab === 'experience'\"]");
    await periodEditor.getByLabel('Start month', { exact: true }).fill('2022-03');
    await periodEditor.getByLabel('End month', { exact: true }).fill('2024-06');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Mar 2022 - Jun 2024');
    await periodEditor.getByLabel('Present / ongoing', { exact: true }).check();
    await expect(periodEditor.getByLabel('End month', { exact: true })).toBeDisabled();
    await expect(page.locator('.editor-paper-wrap')).toContainText('Mar 2022 - Present');
    await page.getByLabel('Edit section').selectOption('education');
    const educationEditor = page.locator(".editor-fields[x-show=\"tab === 'education'\"]");
    await expect(educationEditor).toBeVisible();
    await educationEditor.getByLabel('Start month', { exact: true }).fill('2017-02');
    await educationEditor.getByLabel('End month', { exact: true }).fill('2021-11');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Feb 2017 - Nov 2021');
    await page.getByLabel('Edit section').selectOption('experience');

    await page.getByLabel('Accomplishments (one per line)').fill('Built useful software.\nImproved team workflows.');
    await page.getByRole('button', { name: '+ Add experience' }).click();
    await expect(page.getByLabel('Job title', { exact: true })).toHaveCount(2);
    await page.getByLabel('Job title', { exact: true }).nth(1).fill('Intern');
    await page.getByRole('button', { name: 'Move up', exact: true }).nth(1).click();
    await expect(page.getByLabel('Job title', { exact: true }).nth(0)).toHaveValue('Intern');
    await page.getByRole('button', { name: 'Remove entry', exact: true }).nth(0).click();
    await page.getByRole('button', { name: 'Change template', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Elegant template' }).click();
    await expect(page.locator('.editor-paper-wrap .layout-sidebar')).toBeVisible();
    await expect(page.locator('.editor-paper-wrap')).toContainText('Browser Builder');
    await page.getByLabel('Edit section').selectOption('competencies');
    await page.getByLabel('Key competencies', { exact: true }).fill('Analytical thinking');
    await page.getByLabel('Edit section').selectOption('references');
    await page.getByLabel('References', { exact: true }).fill('Available on request');
    await page.getByRole('button', { name: '+ Add section', exact: true }).click();
    await page.getByLabel('Section title', { exact: true }).fill('Volunteer projects');
    await page.getByLabel('Section content', { exact: true }).fill('Built a community website.');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Volunteer projects');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Analytical thinking');
    await page.getByLabel('Edit section').selectOption('personal');
    await page.getByLabel('Full name', { exact: true }).fill('<img src=x onerror=alert(1)>');
    await expect(page.locator('.editor-paper-wrap img')).toHaveCount(0);
    await page.getByLabel('Full name', { exact: true }).fill('Browser Builder');
    await page.getByRole('button', { name: 'Change template', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Classic template' }).click();
    await expect(page.locator('.editor-save-state')).toHaveText('Draft saved for this session', { timeout: 15000 });
    await page.reload();
    await expect(page.getByLabel('Full name', { exact: true })).toHaveValue('Browser Builder');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Built a community website.');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Mar 2022 - Present');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Feb 2017 - Nov 2021');
    await page.screenshot({ path: 'storage/app/previews/builder-editor.png', fullPage: true });
    for (const width of [320, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: 'storage/app/previews/builder-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Review CV', exact: true }).click();
    await page.getByRole('button', { name: 'Save my CV', exact: true }).click();

    await expect(page).toHaveURL(/cv\/\d+\/edit/);
    await expect(page.getByLabel('Full name', { exact: true })).toHaveValue('Browser Builder');
    await expect(page.getByRole('button', { name: 'Download', exact: true })).toBeVisible();
    await page.locator('.builder-exit').click();
    await expect(page).toHaveURL(/dashboard/);
    await expect(page.locator('.workspace-sidebar')).toHaveAttribute('inert');
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await expect(page.getByRole('button', { name: 'Toggle sidebar' })).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('link', { name: 'My CVs', exact: true }).click();
    await expect(page.getByText('Browser Builder CV', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Edit CV', exact: true }).click();
    await expect(page.getByLabel('Full name', { exact: true })).toHaveValue('Browser Builder');
    await page.goto(base + '/get-started?new=1');
    await page.getByRole('button', { name: /Yes, I have a CV/ }).click();
    const sourcePage = await browser.newPage();
    await sourcePage.setContent('<h1>Alex Morgan</h1><p>alex@example.com</p><h2>Experience</h2><p>Software engineer with practical experience.</p>');
    await sourcePage.pdf({ path: 'storage/app/previews/import-test.pdf' });
    await sourcePage.close();
    await page.getByLabel('Upload your existing CV').setInputFiles('storage/app/previews/import-test.pdf');
    await expect(page.getByRole('heading', { name: /What position/ })).toBeVisible({ timeout: 15000 });
    await page.getByRole('button', { name: 'Skip this step' }).click();
    await page.getByRole('button', { name: 'Choose Classic template' }).click();
    await expect(page.getByLabel('Email', { exact: true })).toHaveValue('alex@example.com');
    await expect(page.getByLabel('Full name', { exact: true })).toHaveValue('Alex Morgan');
    await page.getByText('Text from your uploaded CV', { exact: true }).click();
    await expect(page.locator('.imported-reference pre')).toContainText('Software engineer');
    expect(errors).toEqual([]);
    console.log('Builder browser checks passed: onboarding, 22 templates, live editing, draft reload, responsive layouts, escaped content, login handoff, saved CVs, and PDF import.');
} finally {
    await browser.close();
    if (fixture) execFileSync('php', ['scripts/browser-fixture.php', 'delete', fixture.email], { stdio: 'pipe' });
}
