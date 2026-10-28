import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

if (!process.argv.includes('--live')) throw new Error('Pass --live: this makes one billable analysis request using fictional documents.');
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let fixture;
let submitted;
const report = { created_at: new Date().toISOString(), expected_model: 'gpt-6.1-sol', live_generation_requests: 1 };
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/workspace/checker`);
    await page.getByLabel('Job description', { exact: true }).fill('Junior Web Developer. Required: HTML, CSS, JavaScript, responsive websites and team collaboration. Kubernetes is desirable.');
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await page.locator('.checker-paste summary').click();
    await page.locator('#checker-resume').fill('Alex Example\nalex@example.invalid\nJunior Web Developer\nSummary\nBuilds responsive websites using HTML, CSS and JavaScript.\nExperience\nWeb Developer | Fictional Studio | January 2023 - December 2024\nBuilt responsive websites using HTML, CSS and JavaScript. Collaborated with designers to fix layout issues.\nEducation\nDiploma in Computing | Fictional College | 2022\nSkills\nHTML, CSS, JavaScript, responsive web design, teamwork');
    await page.getByRole('button', { name: 'Next: ATS scan' }).click();
    page.on('request', request => {
        if (request.url().endsWith('/checker/scan') && request.method() === 'POST') submitted = request.postDataJSON();
    });
    const started = Date.now();
    const completed = page.waitForResponse(response => response.url().endsWith('/checker/scan'), { timeout: 130000 });
    await page.getByRole('button', { name: 'ATS Scan', exact: true }).click();
    const response = await completed;
    report.scan_seconds = Number(((Date.now() - started) / 1000).toFixed(2));
    report.scan_http_status = response.status();
    const body = await response.json();
    if (response.status() !== 200) throw new Error(`Scan failed: HTTP ${response.status()}; ${body.message || ''}`);
    expect(body.ai_analysis.summary.length).toBeGreaterThan(0);
    expect(body.ai_analysis.assessment).toBeTruthy();
    expect(body.credits_remaining).toBe(10);
    expect(JSON.stringify(body.ai_analysis.assessment)).toContain('Kubernetes');
    await expect(page.getByRole('heading', { name: 'Your ATS scan result' })).toBeVisible();
    await expect(page.locator('.checker-processing')).toBeHidden();
    expect(errors).toEqual([]);
    report.browser_result_visible = true;
    fs.mkdirSync('storage/app/previews', { recursive: true });
    await page.screenshot({ path: 'storage/app/previews/gpt-sol-live-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'storage/app/previews/gpt-sol-live-mobile.png', fullPage: true });
    report.mobile_no_horizontal_overflow = true;
    // Replay exactly the same operation ID. The server must return the saved result without another model call.
    const replay = await page.evaluate(async input => {
        const response = await fetch('/checker/scan', { method: 'POST', headers: { 'Content-Type': 'application/json',
            Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(input) });
        return { status: response.status, body: await response.json() };
    }, submitted);
    expect(replay.status).toBe(200);
    expect(replay.body.credits_remaining).toBe(10);
    expect(replay.body.analysis_id).toBe(body.analysis_id);
    report.replay_without_extra_charge = true;
    const usage = JSON.parse(execFileSync('php', ['-r',
        "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $user=App\\Models\\User::where('email',$argv[1])->firstOrFail(); echo json_encode(Illuminate\\Support\\Facades\\DB::table('ai_usage_logs')->where('user_id',$user->id)->get(['provider','model','feature','status','input_tokens','output_tokens','credits_charged']));",
        fixture.email], { encoding: 'utf8' }));
    expect(usage).toHaveLength(1);
    expect(usage[0].model).toMatch(/^gpt-6\.1-sol(?:-|$)/);
    expect(usage[0].status).toBe('completed');
    expect(usage[0].input_tokens).toBeGreaterThan(0);
    expect(usage[0].output_tokens).toBeGreaterThan(0);
    report.usage = usage;
    report.javascript_errors = errors;
    report.passed = true;
    console.log(JSON.stringify(report, null, 2));
} catch (error) {
    report.passed = false;
    report.failure = error.message;
    throw error;
} finally {
    fs.mkdirSync('storage/app/private', { recursive: true });
    fs.writeFileSync('storage/app/private/gpt-sol-live-audit.json', JSON.stringify(report, null, 2));
    await browser.close();
    removeTestUser(fixture);
}
