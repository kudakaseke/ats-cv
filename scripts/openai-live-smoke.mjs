import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

// Explicit opt-in: this script makes two billable OpenAI requests using fictional documents.
if (!process.argv.includes('--live')) throw Error('Pass --live to authorise billable API calls.');
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({channel:'chrome',headless:true});
const page = await browser.newPage();
let fixture;
const input = {
    job_description: 'Junior Web Developer. Required: HTML, CSS, JavaScript, responsive websites and team collaboration. Kubernetes is desirable.',
    resume_text: 'Alex Morgan\nalex@example.invalid\nJunior Web Developer\nSummary\nBuilds responsive websites using HTML, CSS and JavaScript.\nExperience\nWeb Developer | Example Studio | January 2023 - December 2024\nBuilt responsive websites using HTML, CSS and JavaScript. Collaborated with designers to fix layout issues.\nEducation\nDiploma in Computing | Example College | 2022\nSkills\nHTML, CSS, JavaScript, responsive web design, teamwork'
};
async function post(path, body) {
    return page.evaluate(async ({path,body}) => {
        const response = await fetch(path, {method:'POST', headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify(body)});
        return {status:response.status,body:await response.json()};
    }, {path,body});
}
try {
    fixture = await signInTestUser(page,base);
    await page.goto(base+'/workspace/checker');
    const original = await page.evaluate(async () => (await fetch('/ai/credits', {headers:{Accept:'application/json'}})).json());
    expect(original.credits).toBe(15);
    const scanInput = {...input,operation_id:crypto.randomUUID()};
    const scan = await post('/checker/scan',scanInput);
    if (scan.status !== 200) throw Error('Live analysis failed: HTTP '+scan.status+'; '+scan.body.message);
    expect(scan.body.ai_analysis.summary.length).toBeGreaterThan(0);
    expect(scan.body.credits_remaining).toBe(10);
    const cachedScan = await post('/checker/scan',scanInput);
    expect(cachedScan.status).toBe(200);
    expect(cachedScan.body.credits_remaining).toBe(10);
    const rewriteInput = {...input,confirmed_keywords:[],operation_id:crypto.randomUUID()};
    const rewrite = await post('/checker/rewrite',rewriteInput);
    if (rewrite.status !== 200) throw Error('Live rewrite failed: HTTP '+rewrite.status+'; '+rewrite.body.message);
    expect(rewrite.body.structured_resume.name).toBe('Alex Morgan');
    expect(rewrite.body.rewritten_resume).toContain('Example Studio');
    expect(rewrite.body.rewritten_resume).toContain('Example College');
    expect(rewrite.body.credits_remaining).toBe(0);
    expect(rewrite.body.changes.length).toBeGreaterThan(0);
    const replay = await post('/checker/rewrite',rewriteInput);
    expect(replay.status).toBe(200);
    expect(replay.body.credits_remaining).toBe(0);
    const blocked = await post('/checker/scan',{...input,operation_id:crypto.randomUUID()});
    expect(blocked.status).toBe(422);
    const usage = JSON.parse(execFileSync('php',['-r',
        "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $user=App\\Models\\User::where('email',$argv[1])->firstOrFail(); echo json_encode(Illuminate\\Support\\Facades\\DB::table('ai_usage_logs')->where('user_id',$user->id)->get(['feature','model','input_tokens','output_tokens','credits_charged','status']));",
        fixture.email],{encoding:'utf8'}));
    expect(usage.length).toBe(2);
    expect(usage.every(row=>row.status==='completed' && row.input_tokens>0 && row.output_tokens>0)).toBe(true);
    await page.goto(base+'/account/activity');
    await expect(page.getByRole('heading',{name:'Account audit trail'})).toBeVisible();
    const report = {success:true,model:usage[0].model,credits:[15,10,0],api_requests:2,idempotent_replays:true,zero_balance_blocked:true,usage};
    fs.writeFileSync('storage/app/openai-live-smoke-report.json',JSON.stringify(report,null,2));
    console.log(JSON.stringify(report,null,2));
} finally {
    await browser.close();
    removeTestUser(fixture);
}
