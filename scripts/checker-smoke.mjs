import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
const base=process.env.APP_TEST_URL || 'http://127.0.0.1:8000';
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage({viewport:{width:1440,height:1100}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const original='Alex Morgan\nalex@example.com\nProfessional Summary\nBuilds websites using HTML and cascading style sheets.\nSkills\nHTML, cascading style sheets\nEducation\nDiploma in Computing\nExample College\n2020';
const structured = {name:'Alex Morgan',role:'',email:'alex@example.com',phone:'',location:'',website:'',summary:'Builds websites using HTML and CSS.',skills:['HTML','CSS'],core_strengths:[],competencies:[],references:'',experience:[{title:'Web Developer',company:'Example Studio',period:'2022 - Present',location:'Harare',responsibilities:['Skills: taught colleagues website testing.','Education: delivered internal workshops.']}],education:[{degree:'Diploma in Computing',school:'Example College',period:'2020 - 2022',location:'Harare'}],achievements:[],courses:[],languages:[],custom_sections:[]};
const fixtureResult = JSON.parse(execFileSync('php', ['-r', "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $mapper=app(App\\Services\\CvStructuredResume::class); $text=$mapper->text($mapper->data(json_decode(stream_get_contents(STDIN),true))); echo json_encode(['text'=>$text,'after'=>app(App\\Services\\CvKeywordMatcher::class)->compare('HTML CSS JavaScript',$text)]);"], {input:JSON.stringify(structured),encoding:'utf8'}));
const rewritten = fixtureResult.text;
const after = fixtureResult.after;
let fixture;
try {
 fixture=JSON.parse(execFileSync('php',['scripts/browser-fixture.php','create'],{encoding:'utf8'}));
 await page.goto(base+'/login');
 await page.getByLabel('Email',{exact:true}).fill(fixture.email);
 await page.getByLabel('Password',{exact:true}).fill(fixture.password);
 await page.getByRole('button',{name:'Log in',exact:true}).click();
 await page.goto(base+'/workspace/checker');
 await page.getByLabel('Job description',{exact:true}).fill('HTML CSS JavaScript');
 await page.getByRole('button',{name:'Next: Upload resume'}).click();
 fs.mkdirSync('storage/app/previews',{recursive:true});
 if (process.argv.includes('--pdf')) {
  const python = process.env.CV_PYTHON_BINARY || fileURLToPath(new URL(process.platform === 'win32' ? '../.venv-ats/Scripts/python.exe' : '../.venv-ats/bin/python', import.meta.url));
  execFileSync(python, ['-c', 'import pymupdf,sys; doc=pymupdf.open(); page=doc.new_page(); page.insert_text((40,40),sys.argv[1]); doc.save(sys.argv[2])', original, 'storage/app/previews/checker-input.pdf']);
  await page.getByLabel('Resume file',{exact:true}).setInputFiles('storage/app/previews/checker-input.pdf');
  await expect(page.locator('.checker-filename')).toHaveText('checker-input.pdf');
 } else {
  await page.getByLabel('Resume file',{exact:true}).setInputFiles({name:'resume.txt',mimeType:'text/plain',buffer:Buffer.from(original)});
  await expect(page.locator('.checker-filename')).toHaveText('resume.txt');
 }
 await page.getByRole('button',{name:'Next: ATS scan'}).click();
 await page.getByRole('button',{name:'ATS Scan',exact:true}).click();
 const dialog=page.getByRole('dialog',{name:'Your ATS scan result'});
 await expect(dialog).toBeVisible();
 await expect(dialog.locator('.checker-dialog-score')).toHaveText('67/100');
 await expect(page.getByText('Tailor your resume with AI',{exact:true})).toHaveCount(0);
 await expect(page.getByRole('button',{name:'Generate AI draft',exact:true})).toHaveCount(0);
 await page.screenshot({path:'storage/app/previews/checker-popup.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
 await page.screenshot({path:'storage/app/previews/checker-popup-mobile.png',fullPage:true});
 let calls=0;
 await page.route('**/checker/rewrite',async route=>{
  calls++;
  const input=route.request().postDataJSON();
  expect(input.resume_text).toBe(original);expect(input.confirmed_keywords).toEqual([]);
  if(process.argv.includes('--ai') && calls===1){await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'AI temporarily unavailable. Your CV is unchanged.'})});return;}
  await new Promise(resolve=>setTimeout(resolve,500));
  await route.fulfill({contentType:'application/json',body:JSON.stringify({structured_resume:structured,rewritten_resume:rewritten,changes:['Used CSS terminology supported by the original CV.'],unsupported_keywords:['JavaScript'],before:{score:12,similarity_percent:11.87},after})});
 });
 await dialog.getByRole('button',{name:'Fix Resume',exact:true}).click();
 expect(calls).toBe(0);
 await expect(dialog.getByRole('alert')).toBeVisible();
 await dialog.locator('input[type=checkbox]').check();
 await dialog.getByRole('button',{name:'Fix Resume',exact:true}).click();
 if(process.argv.includes('--ai')){
  await expect(dialog.getByRole('alert')).toContainText('AI temporarily unavailable');
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await page.getByRole('button',{name:'Fix Resume',exact:true}).click();
 }
 await expect(page.getByRole('heading',{name:'Creating your tailored CV'})).toBeVisible();
 await page.keyboard.press('Escape');
 await expect(page.locator('.checker-result-dialog')).toBeVisible();
 await expect(page).toHaveURL(/get-started/);
 await expect(page.locator('.editor-form')).toBeHidden();
 await expect(page.locator('.editor-preview')).toBeVisible();
 await expect(page.locator('.checker-generated-score')).toContainText('67/100');
 await expect(page.locator('.checker-generated-score')).toContainText(after.keyword_score + '/100');
 await expect(page.locator('.checker-comparison')).toContainText('CSS');
 await expect(page.locator('.checker-comparison')).toContainText('Used CSS terminology');
 await page.getByText('Compare original and tailored text',{exact:true}).click();
 await expect(page.locator('.checker-text-comparison')).toContainText('cascading style sheets');
 const data=await page.evaluate(()=>Alpine.$data(document.querySelector('[x-data]')).data);
 expect(data.experience).toHaveLength(1); expect(data.experience[0].title).toBe('Web Developer'); expect(data.experience[0].company).toBe('Example Studio'); expect(data.experience[0].description).toContain('Skills: taught colleagues'); expect(data.education[0].school).toBe('Example College'); expect(data.education[0].period).toBe('2020 - 2022');
 expect(data.template).toBe('ats-professional');expect(data.skills).toContain('CSS');expect(data.name).toBe('Alex Morgan');
 await expect(page.locator('.editor-paper-wrap .cv-paper')).toContainText('HTML');
 await page.getByRole('button',{name:'Download',exact:true}).click();
 await expect(page.getByRole('button',{name:/^PDF/})).toBeVisible();
 await expect(page.getByRole('button',{name:/^Word/})).toBeVisible();
 if(process.argv.includes('--ai')) {
  const pending = page.waitForEvent('download', {timeout:90000});
  pending.catch(() => {});
  await page.getByRole('button',{name:/^PDF/}).click();
  await (await pending).saveAs('storage/app/previews/checker-generated-cv.pdf');
  const checked = execFileSync('php',['-r', "require 'vendor/autoload.php'; $pdf=(new Smalot\\PdfParser\\Parser)->parseFile('storage/app/previews/checker-generated-cv.pdf'); if(count($pdf->getPages())!==1 || !str_contains($pdf->getText(),'CSS')) exit(1); echo 'Generated PDF validated';"],{encoding:'utf8'});
  expect(checked).toContain('validated');
 }
 expect(errors).toEqual([]);
 console.log('Checker passed: percentage popup, automatic background fix, ATS CV review, server-scored improvement, mobile and downloads'+(process.argv.includes('--ai')?', including failure/retry.':'.'));
} finally {
 await browser.close();
 if(fixture) execFileSync('php',['scripts/browser-fixture.php','delete',fixture.email]);
}
