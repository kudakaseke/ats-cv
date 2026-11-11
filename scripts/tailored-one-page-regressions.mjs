import fs from 'node:fs';
import assert from 'node:assert/strict';
import {chromium} from '@playwright/test';
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
 const page=await browser.newPage({viewport:{width:1100,height:1250}});
 await page.setContent(`<style>*{box-sizing:border-box}body{margin:0}${fs.readFileSync('resources/css/cv-paper.css','utf8')}</style><main id="preview" class="editor-paper-wrap"></main>`);
 await page.addScriptTag({content:fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';", 'const Alpine={data:(_,factory)=>window.builderFactory=factory};')});
 for(const count of [4,20]) {
  const result=await page.evaluate(count=>{
   const template={id:'tailored-reference',layout:'ats-professional',font:'sans',category:'ATS Pass'};
   const cv=window.builderFactory({draft:{step:4,data:{template:template.id,name:'Example Applicant',role:'Software Developer',email:'example@example.com',summary:'Experienced developer delivering reliable applications, supporting colleagues and maintaining accurate documentation. Builds practical solutions using verified technical skills.',skills:'Programming: Python, PHP, JavaScript, SQL, HTML5, CSS3\nFrameworks: Laravel\nDatabases: MySQL, MongoDB\nTools: Git, RESTful APIs, Microsoft 365\nMethods: Agile',core_strengths:'Problem solving, Communication, Teamwork, Attention to detail',education:[{degree:'Bachelor of Science',school:'Example University',period:'2018 - 2021'}],experience:Array.from({length:count},(_,index)=>({company:`Employer ${index+1}`,title:'Developer',period:'2021 - 2025',description:Array(4).fill('Developed and maintained business applications, resolved reported issues, documented technical changes and worked with colleagues to deliver reliable services for users.').join('\n')})),references:'FINAL CONTENT MARKER'}},templates:[template]});
   document.querySelector('#preview').innerHTML=cv.reviewPreview(template);
   const pages=[...document.querySelectorAll('.cv-document-page')];
   return {pages:pages.length,compact:pages[0].classList.contains('cv-tailored-compact'),text:document.querySelector('#preview').textContent,font:parseFloat(getComputedStyle(pages[0]).fontSize),overflow:pages.some(p=>p.lastElementChild.getBoundingClientRect().bottom>p.getBoundingClientRect().bottom-30)};
  },count);
  assert.equal(result.compact,true);
  assert.ok(result.font>=12);
  assert.equal(result.overflow,false);
  assert.ok(result.text.includes('FINAL CONTENT MARKER'));
  if(count===4) {
   assert.equal(result.pages,1,'four-job CV fits on one page');
   await page.emulateMedia({media:'print'});
   const pdf=await page.pdf({path:'storage/app/tailored-one-page-preview.pdf',preferCSSPageSize:true,printBackground:true});
   assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length,1);
   await page.emulateMedia({media:'screen'});
   await page.locator('.cv-paper').screenshot({path:'storage/app/tailored-one-page-preview.png'});
  } else assert.ok(result.pages>1,'longer CVs retain their content across pages');
  console.log(`${count} jobs: ${result.pages} pages, readable text, final content retained`);
 }
} finally {await browser.close();}
