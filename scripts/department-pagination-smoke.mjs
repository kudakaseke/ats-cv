import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from '@playwright/test';
const read = name => JSON.parse(execFileSync('php',['-r',`echo json_encode(require 'config/${name}.php');`],{encoding:'utf8'}));
const templates=read('cv_templates'),departments=read('cv_departments');
const source=fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const css=fs.readFileSync('resources/css/cv-paper.css','utf8');
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
 const page=await browser.newPage({viewport:{width:1000,height:1200}});
 await page.setContent('<style>html,body{margin:0}*{box-sizing:border-box}'+css+'</style><main></main>');
 console.log(await page.evaluate(({source,templates,departments})=>{
  let factory;new Function('Alpine',source)({data:(_n,fn)=>factory=fn});
  const results=[];
  for(const template of templates.filter(t=>t.category==='By Department'))for(const long of [false,true]){
   const cv=factory({draft:{},templates,departments});cv.data=cv.departmentExample(template);cv.data.template=template.id;cv.reviewing=true;
   if(long)cv.data.experience=Array.from({length:7},(_,i)=>({...cv.data.experience[0],title:'Role '+i,description:Array(7).fill('Prepared accurate records, resolved outstanding queries and communicated outcomes to stakeholders.').join('\n')}));
   cv.data.references='FINAL CONTENT MARKER';
   const root=document.querySelector('main');cv.$root=root;
   root.innerHTML='<div class="editor-paper-wrap cv-stage" style="width:816px">'+cv.reviewPreview(template)+'</div>';
   const papers=[...root.querySelectorAll('.cv-document-page')];
   if(!papers.length || (long && papers.length<2))throw Error(template.id+' pagination failed');
   if(!root.textContent.includes('FINAL CONTENT MARKER'))throw Error('Final content missing');
   if(root.querySelectorAll('[data-section="experience"] .position-entry-heading').length!==cv.data.experience.length)throw Error('Experience entries lost');
   for(const paper of papers){
    if(Math.abs(parseFloat(getComputedStyle(paper).fontSize)-12)>.1)throw Error('Font size differs from supplied 9pt template');
    const name=paper.querySelector('.cv-header h2');
    if(name && Math.abs(parseFloat(getComputedStyle(name).fontSize)-12.5*96/72)>.1)throw Error('Name must match 12.5pt reference');
    const heading=paper.querySelector('.cv-section h3');
    if(heading && Math.abs(parseFloat(getComputedStyle(heading).fontSize)-10*96/72)>.1)throw Error('Headings must match 10pt reference');
    if(Math.abs(parseFloat(getComputedStyle(paper).paddingLeft)-691/15)>.01)throw Error('Reference side margins differ');
    for(const section of paper.querySelectorAll('.cv-section'))if(section.getBoundingClientRect().bottom>paper.getBoundingClientRect().bottom-31)throw Error(template.id+' content exceeds print margin');
   }
   cv.fitStage(root.firstElementChild);
   if(Number(root.firstElementChild.style.getPropertyValue('--cv-fit-scale'))!==1)throw Error('Document was shrunk');
   results.push({department:template.name,long,pages:papers.length});
  }
  return results;
 },{source,templates,departments}));
 await page.screenshot({path:'storage/app/department-readable-preview.png',fullPage:true});
 await page.emulateMedia({media:'print'});
 await page.pdf({path:'storage/app/department-readable-preview.pdf',printBackground:true,preferCSSPageSize:true});
}finally{await browser.close();}
