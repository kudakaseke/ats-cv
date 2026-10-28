import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const job = `Software Developer. Required: Diploma or degree in Computing; at least three years of relevant development experience; PHP, Laravel, SQL, Git, REST APIs, automated testing and application troubleshooting. Responsibilities: maintain web applications, document technical changes and collaborate with colleagues. AWS certification is preferred; do not treat it as mandatory. Kubernetes is desirable.`;
const resume = `Alex Example
Software Developer
alex@example.invalid | Johannesburg

Professional Summary
Software developer maintaining PHP and Laravel web applications. Uses SQL, Git and automated testing to support dependable releases and troubleshooting. Works with colleagues to understand requirements and document technical changes.

Professional Experience
Software Developer | Fictional Systems | January 2022 - Present
Johannesburg
Develop and maintain PHP and Laravel web applications for internal service teams.
Write SQL queries for operational reporting and investigate incorrect records with support colleagues.
Build REST APIs for approved internal integrations and document request and response fields.
Write automated tests for form validation, authentication and application workflows.
Use Git branches and review changes with colleagues before release.
Investigate application errors, reproduce faults and document technical changes.
Collaborate with support and design colleagues to clarify user requirements.
Maintain deployment instructions and handover notes without claiming cloud certifications.

Junior Developer | Fictional Web Studio | January 2019 - December 2021
Johannesburg
Maintained PHP websites and SQL-backed forms for small organisations.
Used HTML, CSS and JavaScript to fix layout and accessibility issues.
Assisted with functional testing and recorded reproducible defects.
Updated documentation and discussed fixes with colleagues.
Used Git to track changes and preserve release history.

Education
Diploma in Computing | Fictional College | 2018
Completed

Technical and Professional Skills
PHP, Laravel, SQL, Git, REST APIs, automated testing, troubleshooting, HTML, CSS, JavaScript

Core Competencies and Strengths
Team collaboration, technical documentation, problem solving

Projects
Internal Service Portal
Built a Laravel portal with SQL reports, REST API integrations and automated tests. Documented setup instructions and user workflows.

Languages
English - Fluent

References
Taylor Example | Team Lead | | taylor@example.invalid`;
if (process.argv.includes('--input')) { console.log(JSON.stringify({ synthetic_documents: true, job, resume })); process.exit(0); }
if (!process.argv.includes('--live')) throw new Error('Pass --live: two billable requests, one scan and one rewrite, no automatic retries.');
const report = { started_at: new Date().toISOString(), synthetic_documents: true, document_characters: resume.length,
    job_characters: job.length, expected_model: 'gpt-6.1-sol', generation_requests_expected: 2 };
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const submitted = {};
page.on('request', request => {
    if (request.method() === 'POST' && /\/checker\/(scan|rewrite)$/.test(request.url())) {
        submitted[request.url().endsWith('/scan') ? 'scan' : 'rewrite'] = request.postDataJSON();
    }
});
let fixture;
async function replay(path, input) {
    return page.evaluate(async ({ path, input }) => {
        const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(input) });
        return { status: response.status, body: await response.json() };
    }, { path, input });
}
try {
    fixture = await signInTestUser(page, base);
    await page.goto(`${base}/workspace/checker`);
    await page.getByLabel('Job description', { exact: true }).fill(job);
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    await page.locator('.checker-paste summary').click();
    await page.locator('#checker-resume').fill(resume);
    await page.getByRole('button', { name: 'Next: ATS scan' }).click();
    let started = Date.now();
    let waiting = page.waitForResponse(response => response.url().endsWith('/checker/scan'), { timeout: 135000 });
    await page.getByRole('button', { name: 'ATS Scan', exact: true }).click();
    let response = await waiting;
    report.scan_seconds = Number(((Date.now() - started) / 1000).toFixed(2));
    report.scan_http_status = response.status();
    const scanned = await response.json();
    if (response.status() !== 200) throw new Error(`Scan HTTP ${response.status()}: ${scanned.message || ''}`);
    expect(scanned.credits_remaining).toBe(10);
    expect(scanned.assessment.requirements.length).toBeGreaterThan(8);
    const dialog = page.locator('.checker-result-dialog');
    await expect(dialog.getByRole('heading', { name: 'Your ATS scan result' })).toBeVisible();
    const cachedScan = await replay('/checker/scan', submitted.scan);
    expect(cachedScan.status).toBe(200);
    expect(cachedScan.body.analysis_id).toBe(scanned.analysis_id);
    expect(cachedScan.body.credits_remaining).toBe(10);
    report.scan_replay_without_extra_charge = true;
    started = Date.now();
    waiting = page.waitForResponse(response => response.url().endsWith('/checker/rewrite'), { timeout: 135000 });
    await dialog.getByRole('button', { name: 'Fix Resume', exact: true }).click();
    response = await waiting;
    report.rewrite_seconds = Number(((Date.now() - started) / 1000).toFixed(2));
    report.rewrite_http_status = response.status();
    const draft = await response.json();
    if (response.status() !== 200) throw new Error(`Rewrite HTTP ${response.status()}: ${draft.message || ''}`);
    expect(draft.credits_remaining).toBe(0);
    expect(draft.assessment.original_score.total).toBe(scanned.assessment.original_score.total);
    expect(draft.ai_changes.length).toBeGreaterThan(0);
    expect(draft.post_revamp_validation.approved).toBe(true);
    expect(draft.structured_resume.role).toBe('Software Developer');
    expect(draft.structured_resume.courses).toEqual([]);
    expect(draft.structured_resume.references).toContain('taylor@example.invalid');
    expect(draft.structured_resume.experience.map(item => item.company)).toEqual(['Fictional Systems', 'Fictional Web Studio']);
    expect(JSON.stringify(draft.remaining_warnings)).toMatch(/AWS|Kubernetes/i);
    await expect(page).toHaveURL(/get-started/, { timeout: 15000 });
    await expect(page.getByText('What AI changed', { exact: true })).toBeVisible();
    await expect(page.getByText('Remaining Warnings', { exact: true })).toBeVisible();
    await expect(page.locator('.ai-change-report')).toContainText('Source evidence:');
    const cachedDraft = await replay('/checker/rewrite', submitted.rewrite);
    expect(cachedDraft.status).toBe(200);
    expect(cachedDraft.body.credits_remaining).toBe(0);
    expect(cachedDraft.body.rewritten_resume).toBe(draft.rewritten_resume);
    report.rewrite_replay_without_extra_charge = true;
    report.builder_report_visible = true;
    report.original_score_preserved = true;
    report.genuine_gaps_preserved = true;
    report.requirements = scanned.assessment.requirements.length;
    report.ai_changes = draft.ai_changes.length;
    fs.mkdirSync('storage/app/previews', { recursive: true });
    await page.screenshot({ path: 'storage/app/previews/sol-production-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    report.mobile_no_horizontal_overflow = true;
    await page.screenshot({ path: 'storage/app/previews/sol-production-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
    report.passed = true;
} catch (error) {
    report.passed = false;
    report.failure = error.message;
    process.exitCode = 1;
} finally {
    if (fixture) {
        report.usage = JSON.parse(execFileSync('php', ['-r', "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $user=App\\Models\\User::where('email',$argv[1])->firstOrFail(); echo json_encode(Illuminate\\Support\\Facades\\DB::table('ai_usage_logs')->where('user_id',$user->id)->get(['provider','model','feature','status','input_tokens','output_tokens','credits_charged']));", fixture.email], { encoding: 'utf8' }));
        if (report.passed && (report.usage.length !== 2 || report.usage.some(item => item.model !== 'gpt-6.1-sol' || item.status !== 'completed' || item.input_tokens < 1 || item.output_tokens < 1))) {
            report.passed = false; report.failure = 'Unexpected provider usage records.'; process.exitCode = 1;
        }
    }
    report.javascript_errors = errors;
    fs.mkdirSync('storage/app/private', { recursive: true });
    fs.writeFileSync('storage/app/private/sol-production-browser-audit.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
    await browser.close();
    removeTestUser(fixture);
}
