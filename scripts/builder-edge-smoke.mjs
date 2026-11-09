import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage();
page.setDefaultTimeout(15000);
page.setDefaultNavigationTimeout(20000);
const dialogs = [];
page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
let fixture;
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/get-started?templates=1`);
    await page.getByRole('button', { name: 'Simple', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Essential template', exact: true }).click();
    const name = page.locator('.editor-paper-wrap [data-field="name"]').first();
    await name.fill('Previous valid name');
    await name.press('Tab');
    const longName = 'N'.repeat(151);
    await name.fill(longName);
    await name.press('Tab');
    await expect(page.locator('.builder-alert:visible')).toContainText('too long');
    await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
    await expect.poll(() => dialogs.length).toBe(1);
    await expect(page).toHaveURL(/get-started/);
    await expect(name).toHaveText(longName);
    expect(dialogs.splice(0)).toEqual(['confirm']);
    await name.fill('Corrected valid name');
    await name.press('Tab');
    await page.getByRole('button', { name: 'Review CV', exact: true }).first().click();
    await page.getByRole('button', { name: 'Save my CV', exact: true }).click();
    await expect(page).toHaveURL(/cv\/\d+\/edit/);
    await expect(page.locator('.editor-paper-wrap')).toContainText('Corrected valid name');
    expect(dialogs).toEqual([]);
    console.log('PASS: overlong inline edit remains recoverable, leaving requires explicit discard, corrected value saves and reopens.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
