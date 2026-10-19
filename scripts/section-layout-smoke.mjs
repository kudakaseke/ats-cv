import {chromium} from '@playwright/test';
import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
const templates=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_templates.php');"],{encoding:'utf8'}));
const source=fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
 const page=await browser.newPage({viewport:{width:1440,height:1300}});
 const css=fs.readFileSync('resources/css/cv-paper.css','utf8');
 await page.setContent('<style>*{box-sizing:border-box}body{margin:0}'+css+'</style><main></main>');
 const results=await page.evaluate(({source,templates})=>{
  let factory;new Function('Alpine',source)({data:(_name,fn)=>factory=fn});
  for(const template of templates){
   const cv=factory({draft:{},templates});cv.data.name='Section Test';cv.data.skills='Research';cv.data.education=[{degree:'Degree',school:'University',period:'2020 - 2024',location:'Cape Town'}];cv.$root=document.body;cv.$nextTick=fn=>fn();
   const render=()=>document.querySelector('main').innerHTML=cv.editablePreview(template);
   const act=el=>cv.canvasAction({target:el,preventDefault(){}});
   render();
   const heading=document.querySelector('[data-field="section_titles.education"]');
   if(!heading || heading.getAttribute('contenteditable')!=='plaintext-only')throw Error(template.id+' heading is not editable');
   const pencil=document.querySelector('[data-canvas-action="edit-section"][data-section-id="education"]');
   if(!pencil || pencil.textContent.includes('Entry'))throw Error(template.id+' missing pencil control');
   act(pencil);heading.textContent='Academic Background';cv.commitInline({target:heading});render();
   const renamed=new DOMParser().parseFromString(cv.reviewPreview(template),'text/html').querySelector('[data-section="education"] > h3');
   if(renamed?.textContent!=='Academic Background')throw Error(template.id+' renamed heading lost in review');
   const trash=document.querySelector('[data-canvas-action="delete-section"][data-section-id="skills"]');
   if(!trash)throw Error(template.id+' missing section delete');act(trash);render();
   if(document.querySelector('[data-section="skills"]'))throw Error(template.id+' deleted section returned');
   if(new DOMParser().parseFromString(cv.reviewPreview(template),'text/html').querySelector('[data-section="skills"]'))throw Error(template.id+' deleted section in review');
   const insert=document.querySelector('.canvas-insert-menu');insert.querySelector('select').value='skills';act(insert.querySelector('button'));cv.data.skills='Restored skills';render();
   if(!document.querySelector('[data-section="skills"]'))throw Error(template.id+' insert failed');
   const region=cv.data.section_positions[0].region;
   const review=new DOMParser().parseFromString(cv.reviewPreview(template),'text/html');
   if(!review.querySelector('.'+region+' > [data-section="skills"]'))throw Error(template.id+' review lost section placement');
   // Blank editor sections disappear in review. References must stay at the
   // bottom when its preceding section is one of those omitted sections.
   cv.data.education=[];cv.data.hidden_sections.push('education');cv.data.summary='Actual summary';cv.data.references='References available on request.';
   const targetRegion=template.category==='ATS Pass'?'ats-content':'cv-main';
   cv.data.section_positions=[{section:'references',region:targetRegion,after:'education'}];
   render();
   const reviewed=new DOMParser().parseFromString(cv.reviewPreview(template),'text/html');
   const ordered=[...reviewed.querySelector('.'+targetRegion).children].filter(node=>node.matches('.cv-section'));
   if(ordered.at(-1)?.dataset.section!=='references')throw Error(template.id+' references jumped above content when anchor was omitted');

  }
  const measurements=[];
  document.querySelector('main').innerHTML='';
  for(const template of templates.filter(t=>t.category==='ATS Pass')){
   const cv=factory({draft:{},templates});
   const card=document.createElement('div');card.style.cssText='width:360px;display:inline-block;vertical-align:top;margin:16px';card.innerHTML='<div class="cv-stage">'+cv.preview(template,true)+'</div>';document.querySelector('main').append(card);
   const stage=card.firstElementChild;const paper=stage.firstElementChild;const section=paper.querySelector('.ats-content > .cv-section:last-child');
   const before=paper.getBoundingClientRect();const bottom=section.getBoundingClientRect().bottom-before.top;
   if(bottom/before.height<.85)throw Error(template.id+' excessive bottom whitespace');
   cv.$root=document.body;cv._fitCache=new Map();cv.fitStage(stage);measurements.push({template:template.id,fill:Math.round(bottom/before.height*100)+'%'});
  }
  return measurements;
 },{source,templates});
 console.log('Section deletion, insertion and review placement passed for all 22 templates.',results);
 await page.screenshot({path:'storage/app/previews/ats-full-page-gallery.png',fullPage:true});
}finally{await browser.close();}
