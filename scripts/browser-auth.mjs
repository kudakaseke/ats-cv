import { execFileSync } from 'node:child_process';

export async function signInTestUser(page, base = process.env.APP_TEST_URL || 'http://127.0.0.1:8015', options = {}) {
    const fixture = JSON.parse(execFileSync('php', ['scripts/browser-fixture.php', 'create', ...(options.paymentTest ? ['paystack'] : [])], { encoding: 'utf8' }));
    try {
        await page.goto(`${base}/login`);
        await page.getByLabel('Email', { exact: true }).fill(fixture.email);
        await page.getByLabel('Password', { exact: true }).fill(fixture.password);
        await page.getByRole('button', { name: /^(Log in|Sign in)$/i }).click();
        await page.waitForURL(url => !url.pathname.includes('/login'));
        return fixture;
    } catch (error) {
        removeTestUser(fixture);
        throw error;
    }
}

export function removeTestUser(fixture) {
    if (fixture) execFileSync('php', ['scripts/browser-fixture.php', 'delete', fixture.email], { stdio: 'pipe' });
}
