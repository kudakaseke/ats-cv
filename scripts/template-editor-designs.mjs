import { chromium } from '@playwright/test';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const templates=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_templates.php');"],{encoding:'utf8'}));
const source=fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
 const page=await browser.newPage();
 const results=await page.evaluate(({source,templates})=>{
  let factory;new Function('Alpine',source)({data:(_name,fn)=>{factory=fn;}});
  const cv=factory({draft:{},templates});
  cv.data.name='Template Check';cv.data.summary='Summary';cv.data.skills='Design, Research';
  cv.data.experience=[{title:'Designer',company:'Studio',period:'Mar 2022 - Present',location:'Cape Town',description:'Built useful products.'}];
  cv.data.achievements=[{title:'Team award',description:'Recognised for delivery.'}];
  cv.data.courses=[{title:'Design systems',institution:'Learning studio'}];
  cv.data.languages=[{name:'English',level:'Fluent'}];
  cv.data.education=[{degree:'Degree',school:'University',period:'2018 - 2021',location:'Cape Town'}];
  return templates.map(t=>{
   const gallery = new DOMParser().parseFromString(cv.preview(t, true), 'text/html');
   if (gallery.querySelectorAll('.cv-section').length < 3) throw Error(t.id+' gallery lacks full design content');
   if (gallery.body.textContent.includes('Template Check')) throw Error(t.id+' gallery uses partial draft');
   const normal=new DOMParser().parseFromString(cv.preview(t),'text/html');
   const edit=new DOMParser().parseFromString(cv.editablePreview(t),'text/html');
   const paper=edit.querySelector('.cv-paper');
   if(paper.className!==normal.querySelector('.cv-paper').className)throw Error(t.id+' lost template classes');
   if(/CVEDITFIELD\d+TOKEN/.test(paper.innerHTML))throw Error(t.id+' unresolved editing token');
   for(const id of ['summary','experience','education','skills','achievements','courses','languages']) {
    const a=normal.querySelector(`[data-section="${id}"]`),b=edit.querySelector(`[data-section="${id}"]`);
    if(a&&a.parentElement.className!==b?.parentElement.className)throw Error(t.id+' moved '+id);
   }
   if(!paper.querySelector('[data-field="name"]'))throw Error(t.id+' missing name editing');
   if(!paper.querySelector('input[type=month]'))throw Error(t.id+' missing calendars');
   return t.id;
  });
 },{source,templates});
 console.log('Preserved designs and inline controls for '+results.length+' templates.');
}finally{await browser.close();}
