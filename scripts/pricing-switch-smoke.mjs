import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome' });
let fixture;
try {
    const page = await browser.newPage();
    for (const width of [1366, 390]) {
        await page.setViewportSize({ width, height: 768 });
        await page.goto(base + '/pricing');
        await expect(page.locator('#pricing article')).toHaveCount(3);
        const card = page.locator('#pricing article').filter({ has: page.getByRole('switch') });
        await expect(card.locator('h3:visible')).toHaveText('Monthly');
        await expect(card.locator('.reference-price:visible')).toContainText('4.75');
        await card.getByRole('switch').check();
        await expect(card.locator('h3:visible')).toHaveText('Pro');
        await expect(card.locator('.reference-price:visible')).toContainText('6.25');
        await expect(card.locator('ul:visible')).toContainText('225 credits');
        await expect(card.locator('a:visible')).toHaveAttribute('href', /#plan-pro$/);
        await card.getByRole('switch').uncheck();
        await expect(card.locator('ul:visible')).toContainText('150 credits');
        await expect(page.locator('#pricing article').last()).toContainText('200 credits');
        const sizing = await page.locator('#pricing').evaluate(e => ({ height: e.offsetHeight, overflow: document.documentElement.scrollWidth > innerWidth }));
        expect(sizing.overflow).toBe(false);
        // Full plan feature lists may extend below the viewport.
        console.log('Public pricing switch verified at ' + width);
    }
    fixture = await signInTestUser(page, base);
    await page.goto(base + '/subscription#plan-pro');
    await expect(page.locator('.workspace-pricing article')).toHaveCount(3);
    const card = page.locator('.workspace-pricing article').filter({ has: page.getByRole('switch') });
    await expect(card.getByRole('switch')).toBeChecked();
    await expect(card.locator('a:visible')).toHaveAttribute('href', /\/pro\/payment-method$/);
    await card.getByRole('switch').uncheck();
    await expect(card.locator('a:visible')).toHaveAttribute('href', /\/monthly\/payment-method$/);
    console.log('Subscription checkout selection verified');
} finally {
    await browser.close();
    if (fixture) removeTestUser(fixture);
}

