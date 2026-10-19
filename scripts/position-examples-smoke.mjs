import {chromium} from '@playwright/test';import fs from 'node:fs';import {execFileSync} from 'node:child_process';
const templates=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_templates.php');"],{encoding:'utf8'}));
const groups=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_positions.php');"],{encoding:'utf8'}));
const featured=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_position_featured.php');"],{encoding:'utf8'}));
const source=fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const b=await chromium.launch({channel:'chrome',headless:true});
try{const p=await b.newPage();console.log(await p.evaluate(async({source,templates,groups,featured})=>{
let factory;new Function('Alpine',source)({data:(_name,fn)=>factory=fn});const cv=factory({draft:{},templates,positionGroups:groups,positionFeatured:featured});
if(cv.positionExamples.slice(0,8).some(p=>!p.featured))throw Error('Featured positions not first');
const ids=new Set();for(const position of cv.positionExamples){if(ids.has(position.id))throw Error('Duplicate position');ids.add(position.id);const doc=new DOMParser().parseFromString(cv.positionPreview(position),'text/html');if(!doc.querySelector('.cv-paper')||!doc.body.textContent.includes(position.example?.name || position.title))throw Error(position.title+' invalid preview');if(!doc.querySelector('[data-section="experience"]')||!doc.querySelector('[data-section="education"]'))throw Error(position.title+' incomplete example');}
for(const position of cv.positionExamples.filter(p=>p.featured)){cv.positionSearch=position.example.role;if(!cv.filteredPositions.some(item=>item.id===position.id))throw Error('Detailed role search failed: '+position.example.role);}
cv.positionSearch='software developer';if(!cv.filteredPositions.some(p=>p.title==='Software Developer'))throw Error('Search failed');
cv.positionSearch='';cv.positionIndustry='Nursing';if(!cv.filteredPositions.every(p=>p.industry==='Nursing')||!cv.filteredPositions.length)throw Error('Industry filter failed');
cv.data.name='My Applicant';cv.data.summary='My actual experience';cv.data.imported_text='My imported CV';const before=JSON.stringify(cv.data);const position=cv.positionExamples.find(p=>p.title==='Registered Nurse');let selected;cv.choose=async template=>{selected=template.id;};await cv.choosePosition(position);if(cv.data.role!=='Registered Nurse'||selected!==position.template.id)throw Error('Position selection failed');const after={...cv.data,role:JSON.parse(before).role};if(JSON.stringify(after)!==before)throw Error('Selection overwrote user information');
cv.category='By Position';if(cv.templateGroups.length)throw Error('Design filters mixed into positions');cv.category='All';if(!cv.templateGroups.length)throw Error('Original templates missing');
return {positions:cv.positionExamples.length,industries:groups.length,result:'All previews, search, selection and data preservation passed'};
},{source,templates,groups,featured}));}finally{await b.close();}
