import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
import fs from 'node:fs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome' });
const results = [];
let fixture;
try {
    const page = await browser.newPage();
    page.setDefaultTimeout(15000);
    await page.route('https://fonts.googleapis.com/**', route => route.abort());
    const errors = [];
    page.on('pageerror', error => errors.push({ url: page.url(), message: error.message }));
    async function check(path, width) {
        await page.setViewportSize({ width, height: 900 });
        const response = await page.goto(base + path, { waitUntil: 'domcontentloaded' });
        expect(response.status(), path).toBe(200);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${path} at ${width}px`).toBe(true);
        await expect(page.locator('main')).toBeVisible();
        await expect(page.locator('link[rel="icon"][type="image/svg+xml"]')).toHaveAttribute('href', /favicon\.svg/);
        for (const image of await page.locator('img:visible').all()) {
            await image.evaluate(element => element.decode());
        }
        results.push({ path, width, status: response.status() });
    }
    for (const width of [320, 390, 1440]) {
        for (const path of ['/', '/about', '/features', '/pricing', '/ats-explained', '/contact', '/privacy', '/terms', '/refund-policy', '/login', '/register', '/forgot-password']) {
            await check(path, width);
        }
    }
    fixture = await signInTestUser(page, base);
    for (const width of [320, 390, 1440]) {
        for (const path of ['/dashboard', '/profile', '/subscription', '/workspace/checker', '/workspace/detect', '/workspace/resumes', '/workspace/history', '/subscription/monthly/payment-method', '/subscription/pro/payment-method', '/subscription/annual/payment-method']) {
            await check(path, width);
            await expect(page.locator('a[href$="/account/activity"]')).toHaveCount(0);
            if (path === '/profile') {
                await expect(page.locator('#email')).not.toBeEditable();
            }
            if (path.endsWith('/payment-method')) {
                await expect(page.locator('.payment-method-heading h2')).toHaveText(['Paystack', 'Paynow']);
                for (const heading of await page.locator('.payment-method-heading h2').all()) {
                    await expect(heading).toHaveClass('sr-only');
                }
                await expect(page.locator('.payment-provider-paystack img')).toBeVisible();
                await expect(page.locator('.payment-provider-paynow img')).toBeVisible();
            }
        }
    }
    expect(errors).toEqual([]);
    const wallet = await page.evaluate(async () => (await fetch('/ai/credits', { headers: { Accept: 'application/json' } })).json());
    expect(wallet.credits).toBe(15);
    fs.writeFileSync('storage/app/full-audit-2026-10-22-pages.json', JSON.stringify({ results, errors, credits: wallet.credits }, null, 2));
    console.log(`PASS: ${results.length} public/authenticated page and viewport checks, logos, payment choices, read-only email, no browser errors, and unchanged welcome credits.`);
} finally {
    await browser.close();
    if (fixture) removeTestUser(fixture);
}
