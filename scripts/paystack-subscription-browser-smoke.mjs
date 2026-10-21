import {chromium, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import {signInTestUser, removeTestUser} from './browser-auth.mjs';

if (!process.argv.includes('--test-payments')) throw Error('Pass --test-payments to create Paystack test subscriptions.');
const ready = execFileSync('php', ['-r', "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo app()->environment('local') && App\\Services\\Billing\\PaystackGateway::localTestCreditsEnabled() ? 'test-ready' : 'blocked';"], {encoding:'utf8'});
if (ready.trim() !== 'test-ready') throw Error('Only local Paystack test mode is allowed.');
const base = process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({channel:'chrome',headless:true});
fs.mkdirSync('storage/app/subscription-tests',{recursive:true});
const results=[];
try {
    for (const [plan,name,credits,amount] of [['monthly','Monthly',150,'77.95'],['pro','Pro',225,'102.56'],['annual','Annual',150,'777.83']]) {
        const context = await browser.newContext({viewport:{width:1360,height:1000}});
        const page = await context.newPage();
        let fixture;
        try {
            fixture = await signInTestUser(page,base,{paymentTest:true});
            await page.goto(`${base}/subscription/${plan}/payment-method`);
            const card = page.locator('.payment-method-card').first();
            await card.locator('input[name=billing_consent]').check();
            await card.getByRole('button',{name:'Subscribe with Paystack (test)',exact:true}).click();
            await page.waitForURL(url=>url.hostname==='checkout.paystack.com',{timeout:60000});
            await page.waitForTimeout(2000);
            if (await page.locator('input[name="cf-turnstile-response"]').count()) {
                // Allow automatic verification to finish naturally; never interact with the challenge.
                await page.waitForFunction(() => !document.querySelector('input[name="cf-turnstile-response"]'), null, {timeout:30000}).catch(()=>{});
            }
            const securityCheck = await page.locator('input[name="cf-turnstile-response"]').count() > 0;
            await page.screenshot({path:`storage/app/subscription-tests/${plan}-checkout.png`,fullPage:true});
            const reference = JSON.parse(execFileSync('php',['-r',"require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $user=App\\Models\\User::where('email',$argv[1])->firstOrFail(); echo json_encode(App\\Models\\BillingPayment::where('user_id',$user->id)->sole()->reference);",fixture.email],{encoding:'utf8'}));
            await page.goto(`${base}/payments/${reference}`);
            const wallet = await page.evaluate(async()=>(await fetch('/ai/credits',{headers:{Accept:'application/json'}})).json());
            expect(wallet.purchased_credits).toBe(0);
            const result = {plan,amount_zar:amount,expected_credits_after_payment:credits,checkout_initialized:true,
                payment_completed:false,purchased_credits_before_payment:wallet.purchased_credits,
                hosted_checkout:securityCheck?'security_verification_required':'opened_not_submitted'};
            results.push(result);
            console.log(JSON.stringify(result));
            if (process.argv.includes('--inspect')) break;
        } finally {
            await context.close();
            removeTestUser(fixture);
        }
    }
    fs.writeFileSync('storage/app/subscription-tests/checkout-report.json',JSON.stringify({mode:'test',results},null,2));
} finally { await browser.close(); }
