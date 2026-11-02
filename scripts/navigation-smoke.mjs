import {chromium,expect} from '@playwright/test';
const base=process.env.APP_TEST_URL || 'http://127.0.0.1:8001';
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage();const errors=[],fonts=[],scripts=[];const timings=[];
page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(/fonts\.(googleapis|gstatic)\.com/.test(r.url()))fonts.push(r.url());if(r.resourceType()==='script')scripts.push(r.url());});
try{
 for(const path of ['/','/about','/login','/register']){
  const start=Date.now();await page.goto(base+path,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>!!window.Alpine);
  timings.push({path,ready_ms:Date.now()-start});
  await page.evaluate(()=>document.fonts.ready);
  expect(await page.evaluate(()=>document.fonts.check('14px "Plus Jakarta Sans"'))).toBeTruthy();
 }
 expect(fonts).toEqual([]);
 expect(scripts.some(url=>/\/assets\/app-.*\.js/.test(url))).toBeFalsy();
 await page.goto(base+'/');await page.setViewportSize({width:390,height:844});await page.getByRole('button',{name:'Toggle navigation'}).click();await expect(page.locator('#mobile-nav')).toBeVisible();await page.getByRole('button',{name:'Toggle navigation'}).click();await expect(page.locator('#mobile-nav')).toBeHidden();
 await page.setViewportSize({width:1280,height:900});
 await page.route('**/about',async route=>{await new Promise(r=>setTimeout(r,400));await route.continue();});
 const pendingNow = await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('link',{name:'About',exact:true}).evaluate(link=>{link.click();return document.documentElement.dataset.navigationPending;});
 expect(pendingNow).toBe('true');await page.waitForURL('**/about');
 await page.goBack();await expect(page.locator('html')).not.toHaveAttribute('data-navigation-pending');
 await page.goto(base+'/login');await page.waitForFunction(()=>!!window.Alpine);
 let release;const gate=new Promise(r=>release=r);
 await page.route('**/auth/google',async route=>{await gate;await route.fulfill({contentType:'text/html',body:'<!doctype html><title>Mock Google handoff</title><p>Redirect started.</p>'});});
 const googleFeedback=await page.getByRole('link',{name:'Continue with Google'}).evaluate(link=>{link.click();return {label:link.querySelector('[data-navigation-label]').textContent,pending:document.documentElement.dataset.navigationPending};});
 expect(googleFeedback.label).toMatch(/^Opening Google/);expect(googleFeedback.pending).toBe('true');release();await page.waitForURL('**/auth/google');
 await page.goBack();await expect(page.getByRole('link',{name:'Continue with Google'})).toBeVisible();await expect(page.locator('html')).not.toHaveAttribute('data-navigation-pending');
 expect(errors).toEqual([]);
 console.log(JSON.stringify({result:'passed',checks:'public/auth load, lightweight bundle, local font, mobile menu, click feedback, Back recovery, mocked Google handoff',timings,external_font_requests:fonts.length},null,2));
}finally{await browser.close();}
