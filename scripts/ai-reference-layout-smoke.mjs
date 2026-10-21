import {chromium} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';

const templates=JSON.parse(execFileSync('php',['-r',"echo json_encode(require 'config/cv_templates.php');"],{encoding:'utf8'}));
const source=fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const css=fs.readFileSync('resources/css/cv-paper.css','utf8');
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
    const page=await browser.newPage({viewport:{width:1000,height:1250}});
    await page.setContent('<style>*{box-sizing:border-box}body{background:#e8edf3;margin:24px}'+css+'</style><main></main>');
    const result=await page.evaluate(({templates,source})=>{
        let factory;new Function('Alpine',source)({data:(_name,fn)=>factory=fn});
        const results=[];
        for (const id of ['ats-professional','department-it']) {
            const template=templates.find(item=>item.id===id);
            const cv=factory({draft:{data:{template:id,name:'Alex Example',role:'IT Support Professional',email:'applicant@example.invalid',phone:'0123456789',
                summary:'IT support professional experienced in resolving technical issues and collaborating with colleagues.',
                skills:'Windows, Networking, Troubleshooting, Documentation',
                core_strengths:'Problem Solving | Communication | Teamwork | Attention to Detail',
                competencies:'teamwork, Adaptability, Integrity, Initiative, Organisation',
                experience:[{title:'IT Support Assistant',company:'Example Organisation',period:'Jan 2022 - Present',location:'Cape Town',description:'Resolved support requests.\nDocumented solutions for colleagues.'}],
                education:[{degree:'Diploma in Information Technology',school:'Example College',period:'2020 - 2021',location:''}],
                courses:[{title:'Example Training Certificate',institution:'',period:'2024'}],
                references:'Jane Example | IT Manager | 0121112222 | jane@example.invalid\nSam Example | Lecturer | sam@example.invalid'}},templates});
            cv.reviewing=true;cv.$root=document.querySelector('main');
            cv.$root.innerHTML=cv.reviewPreview(template);
            const paper=cv.$root.querySelector('.cv-paper');
            const grid=paper.querySelector('[data-section="core_strengths"] .cv-skills');
            if (!grid || grid.children.length!==8 || getComputedStyle(grid).gridTemplateColumns.split(' ').length!==5) throw Error(id+' strengths are not 8 distinct items in five columns');
            if (paper.querySelector('[data-section="competencies"]')) throw Error(id+' duplicated competencies section');
            const references=paper.querySelector('[data-section="references"]');
            if (!references || references.querySelectorAll('.cv-reference-row').length!==2 || references.querySelectorAll('.cv-reference-row span').length!==8) throw Error(id+' referees are not separate aligned rows');
            const missingPhone=references.querySelectorAll('.cv-reference-row')[1].children;
            if(missingPhone[2].textContent || !missingPhone[3].textContent.includes('sam@example.invalid'))throw Error(id+' missing phone shifted the referee email into the wrong column');
            if (paper.querySelectorAll('.cv-section').item(paper.querySelectorAll('.cv-section').length-1)!==references) throw Error(id+' references must remain last');
            const style=getComputedStyle(paper);
            if (!style.fontFamily.includes('Arial') || style.fontSize!=='12px' || paper.offsetWidth!==816) throw Error(id+' differs from Word body font or Letter width');
            if(id==='ats-professional') {
                if(paper.querySelector('[data-section="education"] strong').textContent!=='Example College') throw Error('Education institution and qualification were merged onto one bold line');
                if(!paper.querySelector('[data-section="courses"]').textContent.includes('2024')) throw Error('Certification date was lost');
            }
            const item=cv.$root.querySelector('[data-section="core_strengths"]');
            if (/Key competencies/i.test(item.textContent)) throw Error('Old duplicate heading survived');
            const editable=new DOMParser().parseFromString(cv.editablePreview(template),'text/html');
            const field=editable.querySelector('[data-field="core_strengths"]');
            cv.reviewing=false;
            cv.commitInline({target:field,relatedTarget:null});
            if(cv.data.core_strengths.split(',').length!==8)throw Error(id+' blur merged strength words');
            const refField=editable.querySelector('[data-field="references"]');
            cv.commitInline({target:refField,relatedTarget:null});
            if(cv.data.references.split('\n').length!==2 || !cv.data.references.includes(' | '))throw Error(id+' editing flattened referee details');
            results.push({template:id,body_font:style.fontFamily,body_size:style.fontSize,strengths:8,columns:5,references:2,pages:cv.$root.querySelectorAll('.cv-document-page').length});
        }
        return results;
    },{templates,source});
    await page.screenshot({path:'storage/app/ai-reference-layout-preview.png',fullPage:true});
    console.log(JSON.stringify(result,null,2));
} finally {await browser.close();}
