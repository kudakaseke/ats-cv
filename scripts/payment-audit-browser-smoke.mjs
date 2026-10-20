import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
try {
    fixture = await signInTestUser(page, base);
    await page.goto(base + '/dashboard');
    await expect(page.getByRole('heading', { name: 'Current plan: Free', exact: true })).toBeVisible();
    await expect(page.locator('.purchased-credit-badge')).toContainText('0');
    await page.getByRole('link', { name: 'View payments and audit trail' }).click();
    await expect(page.getByRole('heading', { name: 'Account audit trail' })).toBeVisible();
    for (const name of ['Payment history', 'Credit ledger', 'Activity history']) {
        await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    }
    await expect(page.getByText('No payments yet.', { exact: true })).toBeVisible();
    await expect(page.locator('table').nth(1)).toContainText('Welcome');
    await expect(page.locator('table').nth(2)).toContainText('Account login');
    await page.screenshot({ path: 'storage/app/payment-audit-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + '/account/activity');
    await expect(page.getByRole('heading', { name: 'Account audit trail' })).toBeVisible();
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw Error('Mobile page overflows horizontally');
    await page.screenshot({ path: 'storage/app/payment-audit-mobile.png', fullPage: true });
    if (errors.length) throw Error(errors.join('\n'));
    console.log('Account balance, plan visibility, audit navigation, credit ledger, login history and mobile layout passed. No provider or AI calls made.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
