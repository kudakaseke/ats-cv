import {chromium, expect} from '@playwright/test';
import {signInTestUser, removeTestUser} from './browser-auth.mjs';
import fs from 'node:fs';

const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
expect.configure({timeout:15000});
const browser = await chromium.launch({channel:'chrome',headless:true});
const page = await browser.newPage({viewport:{width:1440,height:1000}});
await page.route('https://fonts.googleapis.com/**', route => route.abort());
page.setDefaultTimeout(15000);
page.setDefaultNavigationTimeout(30000);
const errors=[];
page.on('pageerror', error=>errors.push(error.message));
let fixture;
fs.mkdirSync('storage/app/system-audit',{recursive:true});
async function edit(path,value) {
    const field=page.locator('.editor-paper-wrap [data-field="'+path+'"]');
    await field.fill(value);
    await field.press('Tab');
}
try {
    await page.goto(base);
    await page.locator('.reference-start').click();
    await expect(page.getByRole('heading',{name:/Do you have an existing CV/})).toBeVisible();
    await page.getByRole('button',{name:/No, start from scratch/}).click();
    await page.getByLabel('Target position').fill('Accountant');
    await page.getByRole('button',{name:/Next/}).click();
    await expect(page.getByRole('button',{name:'By Department',exact:true})).toHaveAttribute('aria-pressed','true');
    await expect(page.locator('.department-card')).toHaveCount(11);
    await page.getByRole('button',{name:'Use Accounting and Finance template',exact:true}).click();
    await expect(page).toHaveURL(/\/pricing$/);
    await page.goto(base+'/get-started?templates=1');
    await page.getByRole('button',{name:'Simple',exact:true}).click();
    await page.getByRole('button',{name:'Choose Essential template',exact:true}).click();
    await edit('name','System Audit Candidate');
    await edit('email','candidate@example.invalid');
    await edit('phone','0123456789');
    await edit('summary','Accountant with experience in financial reporting and reconciliation.');
    await edit('skills','Excel, Reconciliation, Financial reporting, Communication');
    await edit('experience.0.title','Accounts Assistant');
    await edit('experience.0.company','Example Organisation');
    await edit('experience.0.description','Reconciled monthly accounts.\nPrepared accurate financial reports.');
    await page.locator('[data-date-toggle="experience.0"]').click();
    await page.getByLabel('experience 1 start month and year',{exact:true}).fill('2022-01');
    await page.locator('[data-date-toggle="experience.0"]').click();
    await page.getByLabel('experience 1 Present',{exact:true}).check();
    await edit('education.0.degree','Diploma in Accounting');
    await edit('education.0.school','Example College');
    await edit('section_titles.education','Education and Qualifications');
    await page.getByRole('button',{name:'Review CV',exact:true}).first().click();
    await expect(page.locator('.editor-paper-wrap')).toContainText(/System Audit Candidate/i);
    await expect(page.locator('.editor-paper-wrap')).toContainText('Education and Qualifications');
    await expect(page.locator('.editor-paper-wrap')).toContainText('Jan 2022 - Present');
    await expect(page.locator('.editor-paper-wrap [contenteditable="plaintext-only"]')).toHaveCount(0);
    await page.getByRole('button',{name:'Save my CV',exact:true}).click();
    await expect(page).toHaveURL(/login/);
    fixture=await signInTestUser(page,base);
    await expect(page).toHaveURL(/cv\/\d+\/edit/);
    await expect(page.locator('.editor-paper-wrap')).toContainText(/System Audit Candidate/i);
    if (await page.getByRole('button',{name:'Review CV',exact:true}).first().isVisible()) {
        await page.getByRole('button',{name:'Review CV',exact:true}).first().click();
    }
    for (const [label,extension] of [['PDF','pdf'],['Word','docx']]) {
        await page.getByRole('button',{name:'Download',exact:true}).click();
        const pending=page.waitForEvent('download',{timeout:90000});
        await page.locator('#cv-download-menu').getByRole('button',{name:new RegExp('^'+label)}).click();
        const file=await pending;
        await file.saveAs('storage/app/system-audit/candidate.'+extension);
        expect(fs.statSync('storage/app/system-audit/candidate.'+extension).size).toBeGreaterThan(1000);
    }
    await page.goto(base+'/workspace/resumes');
    await expect(page.getByText('System Audit Candidate CV',{exact:true})).toBeVisible();
    await page.getByRole('link',{name:'Edit CV',exact:true}).click();
    await expect(page.locator('.editor-paper-wrap')).toContainText('Education and Qualifications');
    await page.goto(base+'/get-started?new=1');
    await page.getByRole('button',{name:/Yes, I have a CV/}).click();
    await page.locator('#cv-upload').setInputFiles('storage/app/system-audit/candidate.docx');
    await expect(page.getByRole('heading',{name:/Choose a template/})).toBeVisible();
    await page.getByRole('button',{name:'Simple',exact:true}).click();
    await page.getByRole('button',{name:'Choose Essential template',exact:true}).click();
    await expect(page.locator('.editor-paper-wrap')).toContainText(/System Audit Candidate/i);
    await expect(page.locator('.editor-paper-wrap')).toContainText('Example Organisation');
    for (const [plan,price] of [['monthly','4.75'],['pro','6.50'],['annual','47.40']]) {
        await page.goto(base+'/subscription/'+plan+'/payment-method');
        await expect(page.getByRole('heading',{name:'Choose Payment Method',exact:true})).toBeVisible();
        await expect(page.locator('.payment-method-card h2')).toHaveText(['Paynow']);
        await expect(page.locator('.payment-method-card').first().getByRole('button')).toHaveCount(1);
        await expect(page.locator('.payment-method-card').first()).toContainText('US$'+price);
    }
    for (const path of ['/dashboard','/subscription','/profile','/workspace/checker','/workspace/resumes','/account/activity']) {
        for (const width of [320,390,1440]) {
            await page.setViewportSize({width,height:900});
            const response=await page.goto(base+path);
            expect(response.status()).toBe(200);
            expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        }
    }
    const wallet=await page.evaluate(async()=>(await fetch('/ai/credits',{headers:{Accept:'application/json'}})).json());
    expect(wallet.credits).toBe(15);
    expect(errors).toEqual([]);
    console.log('PASS: guest onboarding, paid-template lock, free-template selection, inline editing, headings, dates, review, sign-in-to-save, reopening, PDF/Word downloads, Word import, all Paynow prices and responsive workspace. No provider or AI charges made.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
