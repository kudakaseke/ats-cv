import { chromium, expect } from '@playwright/test';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {});
const page = await browser.newPage();
const second = await browser.newContext();
const other = await second.newPage();
const errors = [];
for (const tab of [page, other]) {
    tab.setDefaultTimeout(20000);
    tab.on('pageerror', error => errors.push(error.message));
    await tab.route(/fonts\.(googleapis|gstatic)\.com/, route => route.abort());
}
let fixture;
const data = name => ({step: 4, resume_id: null, data: {name, template: 'essential', experience: [], education: [], achievements: [], courses: [], languages: []}});
async function draft(tab, name) {
    const csrf = await tab.locator('meta[name=csrf-token]').getAttribute('content');
    const response = await tab.request.post(base + '/get-started/draft', {headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}, data: data(name)});
    expect(response.ok()).toBe(true);
}
async function loginOther(password) {
    await other.goto(base + '/login');
    await other.getByLabel('Email', {exact: true}).fill(fixture.email);
    await other.getByLabel('Password', {exact: true}).fill(password);
    await other.getByRole('button', {name: /^(Log in|Sign in)$/i}).click();
    await expect(other).toHaveURL(/dashboard/);
}
try {
    fixture = await signInTestUser(page, base);
    await draft(page, 'Preserved audit draft');
    await page.goto(base + '/get-started?new=1');
    await expect(page.getByRole('heading', {name: 'Start a new CV?'})).toBeVisible();
    await page.getByRole('link', {name: 'Continue editing current draft'}).click();
    await expect(page.locator('.editor-paper-wrap')).toContainText('Preserved audit draft');
    await page.goto(base + '/get-started/finish');
    await expect(page.getByRole('heading', {name: 'Save your CV to your account?'})).toBeVisible();
    const listing = await page.request.get(base + '/workspace/resumes');
    expect(await listing.text()).toContain('No saved CVs yet');
    const saving = page.waitForRequest(request => request.url().endsWith('/get-started/finish') && request.method() === 'POST');
    await page.getByRole('button', {name: 'Save my CV', exact: true}).click();
    await saving;
    await expect(page).toHaveURL(/cv\/\d+\/edit/);
    await draft(page, 'Draft to discard explicitly');
    await page.goto(base + '/get-started?new=1');
    await page.getByRole('button', {name: 'Start a new CV', exact: true}).click();
    await expect(page).toHaveURL(base + '/get-started');
    await expect(page.getByRole('heading', {name: /Do you have an existing CV/})).toBeVisible();

    await loginOther(fixture.password);
    await draft(other, 'Private draft in another browser');
    await page.goto(base + '/profile');
    await page.locator('#update_password_current_password').fill(fixture.password);
    const changed = 'Browser-Changed-Password-123!';
    await page.locator('#update_password_password').fill(changed);
    await page.locator('#update_password_password_confirmation').fill(changed);
    await page.locator('form').filter({has: page.locator('#update_password_current_password')}).getByRole('button', {name: 'Save', exact: true}).click();
    await expect(page).toHaveURL(/profile/);
    await other.goto(base + '/get-started');
    await expect(other.locator('body')).not.toContainText('Private draft in another browser');
    await other.goto(base + '/dashboard');
    await expect(other).toHaveURL(/login/);
    await page.goto(base + '/dashboard');
    await expect(page).toHaveURL(/dashboard/);

    await loginOther(changed);
    await draft(other, 'Private draft before deletion');
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const deletion = await page.request.delete(base + '/profile', {headers: {'X-CSRF-TOKEN': csrf}, data: {password: changed}, maxRedirects: 0});
    expect(deletion.status()).toBe(302);
    await other.goto(base + '/get-started');
    await expect(other.locator('body')).not.toContainText('Private draft before deletion');
    await other.goto(base + '/dashboard');
    await expect(other).toHaveURL(/login/);
    expect(errors).toEqual([]);
    console.log('PASS: GETs preserve drafts and do not create CVs; explicit POST save/discard work; password changes revoke the other browser; account deletion removes its session and private draft.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
