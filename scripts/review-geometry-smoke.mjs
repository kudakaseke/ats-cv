import {chromium} from '@playwright/test';import fs from 'node:fs';import {execFileSync} from 'node:child_process';
const templates=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_templates.php');"],{encoding:'utf8'}));
const source=fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const manifest=JSON.parse(fs.readFileSync('public/build/manifest.json','utf8'));const css=fs.readFileSync('public/build/'+manifest['resources/css/app.css'].file,'utf8');
const b=await chromium.launch({channel:'chrome',headless:true});
try{const p=await b.newPage({viewport:{width:1440,height:1200}});await p.setContent('<style>'+css+'</style><main></main>');console.log(await p.evaluate(({source,templates})=>{
let factory;new Function('Alpine',source)({data:(_name,fn)=>factory=fn});let checked=0;
for(const template of templates)for(const sparse of [true,false]){
const cv=factory({draft:{},templates});cv.data.name='Geometry Test';cv.data.summary='A real summary used to compare line wrapping across editing and review.';
if(!sparse){cv.data.skills='Research, Communication';cv.data.experience=[{title:'Designer',company:'Studio',period:'Mar 2022 - Present',location:'Cape Town',description:'Built products.\nImproved customer journeys.'}];cv.data.education=[{degree:'Degree',school:'University',period:'2018 - 2021',location:'Cape Town'}];cv.data.courses=[{title:'Course',institution:'Institute'}];cv.data.languages=[{name:'English',level:'Fluent'}];}
const measure=review=>{document.querySelector('main').innerHTML='<section class="builder-editor '+(review?'is-reviewing':'')+'"><div class="editor-paper-wrap cv-stage" style="width:'+cv.pageDimensions(template.id).designWidth+'px">'+(review?cv.reviewPreview(template):cv.editablePreview(template))+'</div></section>';const paper=document.querySelector('.cv-paper');const base=paper.getBoundingClientRect();return {paper:{width:base.width,height:base.height},sections:[...paper.querySelectorAll('.cv-section')].map(el=>{const r=el.getBoundingClientRect();return {id:el.dataset.section,x:r.x-base.x,y:r.y-base.y,width:r.width,height:r.height};})};};
const edit=measure(false),review=measure(true);if(edit.sections.length!==review.sections.length)throw Error(template.id+' section count changed');
for(let i=0;i<edit.sections.length;i++)for(const prop of ['x','y','width','height'])if(Math.abs(edit.sections[i][prop]-review.sections[i][prop])>1)throw Error(template.id+' sparse='+sparse+' '+edit.sections[i].id+' '+prop+' '+edit.sections[i][prop]+' -> '+review.sections[i][prop]);
if(Math.abs(edit.paper.height-review.paper.height)>1)throw Error(template.id+' page height changed');checked++;
}return 'Identical editing/review geometry for '+checked+' template/content combinations.';
},{source,templates}));}finally{await b.close();}
