import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage();
page.setDefaultTimeout(15000);
page.setDefaultNavigationTimeout(20000);
let fixture;
const prompts = [];
let discard = false;
page.on('dialog', async dialog => {
    prompts.push(dialog.type());
    if (discard) await dialog.accept();
    else await dialog.dismiss();
});
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/get-started?templates=1`);
    await page.getByRole('button', { name: 'Simple', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Essential template', exact: true }).click();
    const name = page.locator('.editor-paper-wrap [data-field="name"]').first();
    const email = page.locator('.editor-paper-wrap [data-field="email"]').first();
    await name.click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Navigation Candidate');
    await email.click();
    await expect(email).toBeFocused();
    await page.keyboard.type('invalid-email');
    await page.locator('.builder-progress-row').click();
    await expect(email).toHaveText('invalid-email');
    await expect(name).toHaveText('Navigation Candidate');
    await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
    await expect(page.locator('.builder-alert:visible')).toContainText('email');
    await expect(page).toHaveURL(/get-started/);
    expect(prompts.splice(0)).toEqual(['confirm']);
    const guarded = await page.evaluate(() => {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        return event.defaultPrevented;
    });
    expect(guarded).toBe(true);
    discard = true;
    await page.getByRole('link', { name: 'Go back', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    console.log('Discard navigation prompts: ' + JSON.stringify(prompts));
    expect(prompts.splice(0)).toEqual(['confirm']);
    await page.goto(`${base}/get-started?templates=1`);
    await page.getByRole('button', { name: 'Simple', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Essential template', exact: true }).click();
    await email.fill('candidate@example.invalid');
    await email.press('Tab');
    await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    expect(prompts).toEqual([]);
    console.log('PASS: pointer-based consecutive field editing, cancel preserves data, unsaved changes stay guarded, Back discards with one confirmation and valid navigation needs none.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
