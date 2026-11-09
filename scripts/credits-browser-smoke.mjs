import {chromium, expect} from '@playwright/test';
import {signInTestUser, removeTestUser} from './browser-auth.mjs';
const browser = await chromium.launch({channel:'chrome',headless:true});
const page = await browser.newPage({viewport:{width:1440,height:1100}});
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
let fixture;
try {
    fixture = await signInTestUser(page,base);
    await page.goto(base+'/dashboard');
    await expect(page.getByText('15 credits',{exact:true})).toBeVisible();
    await page.goto(base+'/subscription');
    await expect(page.locator('#plan-monthly')).toContainText('$4.75');
    await expect(page.locator('#plan-pro')).toContainText('$6.50');
    await expect(page.locator('#plan-annual')).toContainText('US$47.40');
    await expect(page.locator('#credit-topups')).toContainText('150 credits');
    await expect(page.locator('#credit-topups')).toContainText('US$4.75');
    await page.goto(base+'/workspace/checker');
    // Browser-only response fixture; never make a paid OpenAI or payment request.
    await page.route('**/checker/scan', async route => {
        const body = route.request().postDataJSON();
        if (!/^[a-f0-9-]{36}$/.test(body.operation_id)) throw Error('Missing idempotent operation ID');
        await route.fulfill({json:{score:50,similarity_percent:50,keyword_score:50,total:2,matched:['PHP'],missing:['HTML'],suggestions:[],credits_remaining:10,ai_analysis:{summary:'Your PHP experience is relevant.',recommendations:['Describe your actual PHP work.']}}});
    });
    await page.getByLabel('Job description',{exact:true}).fill('PHP HTML');
    await page.getByRole('button',{name:/Next: Upload resume/}).click();
    await page.getByText('Or paste resume text',{exact:true}).click();
    await page.locator('#checker-resume').fill('Alex Morgan\nSkills\nPHP');
    await page.getByRole('button',{name:/Next: ATS scan/}).click();
    await page.getByRole('button',{name:'ATS Scan',exact:true}).click();
    await expect(page.locator('.checker-credit-balance')).toContainText('10 credits available');
    await expect(page.locator('dialog')).toContainText('Describe your actual PHP work.');
    await page.screenshot({path:'storage/app/credits-checker-preview.png',fullPage:true});
    console.log('Dashboard, all four plan prices, approved top-up bundle, operation IDs, credit updates and AI recommendations passed. No paid calls made.');
} finally {
    await browser.close();
    removeTestUser(fixture);
}
