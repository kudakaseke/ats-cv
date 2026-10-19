import { signInTestUser, removeTestUser } from './browser-auth.mjs';
import { chromium, expect } from '@playwright/test';
import fs from 'node:fs';
fs.mkdirSync('storage/app/previews', {recursive:true});
const browser = await chromium.launch({channel:'chrome',headless:true});
const page = await browser.newPage({viewport:{width:1440,height:1000}});
const errors=[]; page.on('pageerror', e=>errors.push(e.message));
let auditFixture;
try {
    auditFixture = await signInTestUser(page, process.env.APP_TEST_URL || 'http://127.0.0.1:8000');
 await page.goto((process.env.APP_TEST_URL || 'http://127.0.0.1:8000')+'/get-started?new=1');
 await expect(page.getByRole('link',{name:'Dashboard',exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Change template',exact:true}).click();
 await page.getByRole('button',{name:'Choose Elegant template',exact:true}).click();
 await page.evaluate(() => { const b = Alpine.$data(document.querySelector('[x-data]')); b.data = { ...b.data, ...b.atsExample(b.templates.find(t => t.id === 'ats-professional')), template: 'elegant', name: 'Export Review Tester', core_strengths: 'Adaptability', competencies: 'Project planning', custom_sections: [{title: 'Volunteering', content: 'Community support export marker'}] }; });
 await page.getByLabel('Full name',{exact:true}).fill('Export Review Tester');
 await page.getByRole('button',{name:'Expand editor',exact:true}).click();
 await expect(page.locator('.editor-preview')).toBeHidden();
 await page.getByLabel('Email',{exact:true}).fill('review@example.com');
 await page.getByRole('button',{name:'Download',exact:true}).click();
 await expect(page.locator('#cv-download-menu')).toBeHidden();
 await page.getByRole('button',{name:'Edit CV',exact:true}).click();
 await page.getByRole('button',{name:/Done editing/}).click();
 await expect(page.locator('.editor-form')).toBeHidden();
 await expect(page.locator('.editor-preview')).toBeVisible();
 await expect(page.locator('.editor-review-banner')).toHaveCount(0);
 await expect(page.locator('.editor-paper-heading')).not.toContainText('Elegant');
 await page.getByRole('button',{name:'Zoom out',exact:true}).click();
 await expect(page.locator('.review-zoom output')).toHaveText('90%');
 await expect.poll(() => page.locator('.editor-paper-wrap').evaluate(el => Math.round(el.getBoundingClientRect().width))).toBe(715);
 await page.getByRole('button',{name:'Zoom in',exact:true}).click();
 await expect(page.locator('.review-zoom output')).toHaveText('100%');
 await page.getByRole('slider',{name:'CV zoom percentage'}).fill('150');
 await expect(page.locator('.review-zoom output')).toHaveText('150%');
 await expect.poll(() => page.locator('.editor-paper-wrap').evaluate(el => Math.round(el.getBoundingClientRect().width))).toBe(1191);
 for (const [label,extension] of [['PDF','pdf'],['Word (.docx)','docx']]) {
  await page.getByRole('button',{name:'Download',exact:true}).click();
  const response=page.waitForResponse(r=>r.url().endsWith('/get-started/export'));
  const download=page.waitForEvent('download',{timeout:90000});
  download.catch(() => {}); // Preserve a failed HTTP response instead of masking it during cleanup.
  await page.getByRole('button',{name:new RegExp('^'+label.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'))}).click();
  const r=await response;
  if(!r.ok()) throw new Error(await r.text());
  const file=await download;
  await file.saveAs('storage/app/previews/download-test.'+extension);
  expect(fs.statSync('storage/app/previews/download-test.'+extension).size).toBeGreaterThan(1000);
 }
 await page.screenshot({path:'storage/app/previews/download-review.png',fullPage:true});
 await page.getByRole('button',{name:'Edit CV',exact:true}).click();
 await expect(page.getByLabel('Full name',{exact:true})).toBeVisible();
 await page.setViewportSize({width:390,height:844});
 await expect(page.getByRole('link',{name:'Dashboard',exact:true})).toBeVisible();
 expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
 expect(errors).toEqual([]);
 console.log('Review, expanded editor, dashboard, PDF and editable Word downloads passed.');
} finally { removeTestUser(auditFixture);await browser.close();}
