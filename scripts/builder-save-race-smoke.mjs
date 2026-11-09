import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage();
page.setDefaultTimeout(15000);
page.setDefaultNavigationTimeout(20000);
const errors = [], dialogs = [], writes = [];
page.on('pageerror', error => errors.push(error.message));
page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.accept(); });
let fixture, release, captured;
const held = new Promise(resolve => { captured = resolve; });
const delayed = new Promise(resolve => { release = resolve; });
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/get-started?templates=1`);
    await page.getByRole('button', { name: 'Simple', exact: true }).click();
    await page.getByRole('button', { name: 'Choose Essential template', exact: true }).click();
    const name = page.locator('.editor-paper-wrap [data-field="name"]').first();
    const saved = page.waitForResponse(response => response.url().endsWith('/get-started/draft') && response.request().postDataJSON()?.data?.name === 'Before slow save');
    await name.fill('Before slow save');
    await name.press('Tab');
    expect((await saved).ok()).toBe(true);
    await page.getByRole('button', { name: 'Review CV', exact: true }).first().click();
    let intercepted = false;
    await page.route('**/get-started/draft', async route => {
        const payload = route.request().postDataJSON();
        writes.push(payload);
        if (!intercepted) {
            intercepted = true;
            captured(payload);
            await delayed;
        }
        await route.continue();
    });
    await page.getByRole('button', { name: 'Save my CV', exact: true }).click();
    expect((await held).data.name).toBe('Before slow save');
    await page.getByRole('button', { name: 'Edit CV', exact: true }).click();
    await name.fill('Newest name after pending save');
    await name.press('Tab');
    await expect(name).toHaveText('Newest name after pending save');
    release();
    await expect(page).toHaveURL(/cv\/\d+\/edit/, { timeout: 15000 });
    await expect(page.locator('.editor-paper-wrap')).toContainText('Newest name after pending save');
    expect(writes.at(-1).data.name).toBe('Newest name after pending save');
    await page.reload();
    await expect(page.locator('.editor-paper-wrap')).toContainText('Newest name after pending save');
    expect(dialogs).toEqual([]);
    expect(errors).toEqual([]);
    console.log('PASS: delayed real draft save followed by an edit persists the newest value before navigation and after reload, without discard prompts.');
} finally {
    release();
    await browser.close();
    removeTestUser(fixture);
}
