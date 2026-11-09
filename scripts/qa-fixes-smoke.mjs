import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(20000);
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    fixture = await signInTestUser(page, base);
    const toggle = page.getByRole('button', { name: 'Toggle sidebar', exact: true, includeHidden: true });
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('nav[aria-label="Workspace navigation"] a').first()).toBeFocused();
    await expect(page.locator('.workspace-main')).toHaveAttribute('inert');
    for (let index = 0; index < 18; index++) {
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.getElementById('workspace-navigation').contains(document.activeElement))).toBe(true);
    }
    for (let index = 0; index < 18; index++) {
        await page.keyboard.press('Shift+Tab');
        expect(await page.evaluate(() => document.getElementById('workspace-navigation').contains(document.activeElement))).toBe(true);
    }
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await expect(page.locator('.workspace-main')).not.toHaveAttribute('inert');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('link', { name: 'Saved checks', exact: true }).click();
    await expect(page).toHaveURL(/workspace\/history/);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('link', { name: 'Audit trail', exact: true }).click();
    await expect(page).toHaveURL(/account\/activity/);
    await expect(page.getByRole('link', { name: 'Billing management' })).toHaveCount(0);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await expect(page.locator('.sidebar-group-heading').first()).toHaveCSS('color', 'rgb(112, 112, 112)');
    const color = await page.locator('.sidebar-group-heading').first().evaluate(el => getComputedStyle(el).color);
    const values = color.match(/\d+/g).slice(0, 3).map(Number).map(v => v / 255).map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
    const contrast = 1.05 / (0.2126 * values[0] + 0.7152 * values[1] + 0.0722 * values[2] + 0.05);
    expect(contrast).toBeGreaterThanOrEqual(4.5);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#workspace-navigation')).not.toHaveAttribute('inert');
    const firstLink = page.locator('nav[aria-label="Workspace navigation"] a').first();
    await firstLink.focus();
    await expect(firstLink).toBeFocused();
    await page.setViewportSize({ width: 320, height: 844 });
    await expect(toggle).toBeFocused();
    for (const width of [320, 851, 900]) {
        await page.setViewportSize({ width, height: 844 });
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('.workspace-main')).toHaveAttribute('inert');
        await page.getByRole('button', { name: 'Close sidebar', exact: true }).click();
        await expect(toggle).toBeFocused();
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await page.locator('.sidebar-overlay').click({ position: { x: 300, y: 100 } });
        await expect(toggle).toBeFocused();
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await page.setViewportSize({ width: 901, height: 844 });
        await expect(toggle).toBeHidden();
        await expect(page.locator('.workspace-main')).not.toHaveAttribute('inert');
        await expect(firstLink).toBeVisible();
    }
    expect(errors).toEqual([]);
    console.log(`PASS: mobile focus containment/restore, saved checks and audit navigation, section heading contrast ${contrast.toFixed(2)}:1; close/overlay/resize at 320, 851, 900 and 901px.`);
} finally {
    await browser.close();
    removeTestUser(fixture);
}
