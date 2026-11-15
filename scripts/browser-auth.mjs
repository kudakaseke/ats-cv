import { execFileSync } from 'node:child_process';

export async function signInTestUser(page, base = process.env.APP_TEST_URL || 'http://127.0.0.1:8015', options = {}) {
    const fixture = JSON.parse(execFileSync('php', ['scripts/browser-fixture.php', 'create', ...(options.paymentTest ? ['payment'] : []), ...(options.pro ? ['pro'] : [])], { encoding: 'utf8' }));
    try {
        await page.addInitScript(() => localStorage.setItem('ats_cookie_notice_v1', 'seen'));
        await page.route(/fonts\.(googleapis|gstatic)\.com/, route => route.abort());
        await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
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

export async function changeTemplate(page) {
    let resolveDialog;
    const dialogMessage = new Promise(resolve => { resolveDialog = resolve; });
    page.once('dialog', async dialog => {
        const message = dialog.message();
        await dialog.accept();
        resolveDialog(message);
    });
    await page.getByRole('button', { name: 'Change template', exact: true }).click();
    const message = await dialogMessage;
    if (!message.includes('recommended ATS-friendly template')) {
        throw new Error(`Unexpected template-change confirmation: ${message}`);
    }
}

export function removeTestUser(fixture) {
    if (fixture) execFileSync('php', ['scripts/browser-fixture.php', 'delete', fixture.email], { stdio: 'pipe' });
}
