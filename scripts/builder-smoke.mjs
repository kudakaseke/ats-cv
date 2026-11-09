import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(20000);
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    fixture = await signInTestUser(page, base, { pro: true });
    await page.goto(`${base}/get-started?templates=1`);
    const catalogue = JSON.parse(execFileSync('php', ['-r', "echo json_encode(require 'config/cv_templates.php');"], { encoding: 'utf8' }));
    await page.getByRole('button', { name: 'All', exact: true }).click();
    const visible = await page.locator('.template-card:visible').count();
    expect(visible).toBe(catalogue.length);
    await page.getByRole('button', { name: 'Simple', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Essential template', exact: true }).click();
    const name = page.locator('.editor-paper-wrap [data-field="name"]').first();
    const email = page.locator('.editor-paper-wrap [data-field="email"]').first();
    await name.fill('Browser Builder');
    await name.press('Tab');
    await email.fill('not-an-email');
    await email.press('Tab');
    await expect(email).toHaveText('not-an-email');
    page.once('dialog', dialog => dialog.dismiss());
    await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
    await expect(page.locator('.builder-alert:visible')).toContainText('email', { timeout: 20000 });
    await expect(page.locator('.builder-alert:visible')).not.toContainText('data.email');
    await expect(name).toHaveText('Browser Builder');
    await expect(page).toHaveURL(/get-started/);
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.goto(`${base}/get-started?templates=1`);
    await page.getByRole('button', { name: 'Simple', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Essential template', exact: true }).click();
    await page.locator('.editor-paper-wrap [data-field="name"]').first().fill('Saved Browser Builder');
    await page.locator('.editor-paper-wrap [data-field="name"]').first().press('Tab');
    await page.locator('.editor-paper-wrap [data-field="email"]').first().fill('builder@example.invalid');
    await page.locator('.editor-paper-wrap [data-field="email"]').first().press('Tab');
    await page.getByRole('button', { name: 'Review CV', exact: true }).first().click();
    await page.getByRole('button', { name: 'Save my CV', exact: true }).click();
    await expect(page).toHaveURL(/cv\/\d+\/edit/);
    await expect(page.locator('.editor-paper-wrap')).toContainText('Saved Browser Builder');
    await page.goto(`${base}/get-started?templates=1`);
    await page.getByRole('button', { name: 'All', exact: true }).click();
    // Exercise every design using its public selection control, not Alpine internals.
    for (const template of catalogue.filter(t => t.category !== 'By Department')) {
        await page.getByRole('button', { name: `Choose ${template.name} template`, exact: true }).click();
        await expect(page.locator('.editor-paper-wrap .cv-paper').first()).toHaveAttribute('data-template', template.id);
        await page.getByRole('button', { name: 'Change template', exact: true }).click();
        await expect(page.locator('.editor-save-state')).not.toHaveText(/^Saving/, { timeout: 15000 });
        // Stay below the real save limiter while exercising every design.
        await page.waitForTimeout(1400);
        console.log('Selected template successfully.');
    }
    await page.getByRole('button', { name: 'By Department', exact: true }).click();
    const departments = page.locator('.department-card');
    for (let index = 0; index < await departments.count(); index++) {
        const card = departments.nth(index);
        await card.locator('select').selectOption({ index: 1 });
        await card.getByRole('button', { name: /^Use .* template$/ }).click();
        await expect(page.locator('.builder-editor')).toBeVisible();
        await page.getByRole('button', { name: 'Change template', exact: true }).click();
        await expect(page.locator('.editor-save-state')).not.toHaveText(/^Saving/, { timeout: 15000 });
        // Stay below the real save limiter while exercising every design.
        await page.waitForTimeout(1400);
        console.log('Selected template successfully.');
    }
    expect(errors).toEqual([]);
    console.log(`PASS: all ${catalogue.length} templates selectable; invalid draft stay/discard, validation labels and saved CV reopen.`);
} finally {
    await browser.close();
    removeTestUser(fixture);
}
