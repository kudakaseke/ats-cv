import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';
import { execFileSync } from 'node:child_process';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
let fixture;
try {
    fixture = await signInTestUser(page, base);
    const response = await page.goto(`${base}/subscription`);
    expect(response.status()).toBe(200);
    for (const plan of ['monthly', 'annual']) {
        await page.locator(`#plan-${plan}`).getByRole('link', { name: 'Choose payment method' }).click();
        await expect(page.getByRole('heading', { name: 'Choose payment method', exact: true })).toBeVisible();
        expect(await page.locator('.payment-provider-icon svg').count()).toBe(3);
        await page.setViewportSize({ width: 390, height: 844 });
        await expect.poll(() => page.locator('.workspace-sidebar').evaluate(el => el.getBoundingClientRect().right)).toBeLessThanOrEqual(1);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
        await page.screenshot({ path: `storage/app/previews/payment-methods-${plan}-mobile.png`, fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        const card = page.locator('.payment-method-card').filter({ has: page.getByRole('heading', { name: 'Paynow', exact: true }) });
        await expect(card.getByRole('button', { name: 'Pay with Paynow (test)', exact: true })).toBeEnabled();
        await expect(card.locator('form[action*="/billing/paynow/"]')).toHaveAttribute('action', `${base}/billing/paynow/${plan}`);
        await expect(card.locator('form[action*="/billing/paynow/"] input[name=billing_consent]')).not.toBeChecked();
        await page.getByRole('link', { name: 'Back to plans', exact: true }).click();
    }
    const insecureAssets = await page.locator('script[src],link[rel=stylesheet]').evaluateAll(elements => elements.map(el => el.src || el.href).filter(url => url.startsWith('http:')));
    if (base.startsWith('https:')) expect(insecureAssets).toEqual([]);
    const reference = execFileSync('php', ['-r', `
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        if (!$app->environment('local')) { throw new RuntimeException('Local only'); }
        $email = trim(stream_get_contents(STDIN));
        if (!preg_match('/^browser-smoke-[a-f0-9]{16}@example\\.invalid$/', $email)) { throw new RuntimeException('Invalid fixture'); }
        $user = App\\Models\\User::where('email', $email)->firstOrFail();
        $payment = App\\Models\\BillingPayment::create(['reference'=>(string) Illuminate\\Support\\Str::uuid(),
            'user_id'=>$user->id, 'subscription_plan_id'=>App\\Models\\SubscriptionPlan::where('slug','monthly')->firstOrFail()->id,
            'provider'=>'paynow', 'mode'=>'test', 'amount_cents'=>500, 'currency'=>'USD', 'billing_interval'=>'monthly']);
        echo $payment->reference;
    `], { input: fixture.email, encoding: 'utf8' }).trim();
    const statusPage = await page.goto(`${base}/payments/${reference}`);
    expect(statusPage.status()).toBe(200);
    await expect(page.getByText('Paynow test payment', { exact: false })).toBeVisible();
    await page.getByRole('button', { name: 'Check payment status', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Payment status', exact: true })).toBeVisible();
    console.log('PASS: Paynow test buttons, HTTPS assets, payment status rendering and status-check button. No provider payment initiated.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
