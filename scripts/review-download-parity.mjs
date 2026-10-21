import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from '@playwright/test';
const templates = JSON.parse(execFileSync('php', ['-r', "echo json_encode(require 'config/cv_templates.php');"], {encoding:'utf8'}));
const source = fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';",'');
const css = fs.readFileSync('resources/css/cv-paper.css','utf8');
const browser = await chromium.launch({channel:'chrome',headless:true});
try {
    const page = await browser.newPage({viewport:{width:1440,height:1200}});
    await page.setContent('<style>*{box-sizing:border-box}'+css+'</style><main></main>');
    console.log(await page.evaluate(({source,templates}) => {
        let factory; new Function('Alpine',source)({data:(_n,fn)=>factory=fn});
        let count=0;
        for(const template of templates) for(const long of [false,true]) {
            const cv=factory({draft:{},templates});
            cv.data={...cv.data,template:template.id,name:'Preview Test',email:'person@example.com',phone:'0123456789',website:'',summary:'Professional summary.',skills:'Communication, Analysis; Excel|Reporting\nPlanning, Research, Leadership, Writing',
                experience:Array.from({length:long?8:1},(_,i)=>({title:'Specialist '+i,company:'Organisation',period:'Jan 2020 - Present',location:'Cape Town',description:Array(long?8:2).fill('Delivered accurate reports and supported the team with clear communication.').join('\n')}))};
            cv.reviewing=true;
            const root=document.querySelector('main');cv.$root=root;
            const measure=print=>{
                root.className='';root.innerHTML='<div class="editor-paper-wrap cv-stage" style="width:794px">'+cv.reviewPreview(template)+'</div>';
                const grid=root.querySelector('.cv-skills');
                if(root.textContent.includes('Dates'))throw Error(template.id+' empty date placeholder leaked into review');
                if(grid && (grid.children.length!==8 || getComputedStyle(grid).gridTemplateColumns.split(' ').length!==5))throw Error(template.id+' skills must have five columns');
                if(root.querySelector('.cv-contact [data-field="website"]'))throw Error(template.id+' empty website remains in review');
                if(print)cv.fitPrintedPage();else cv.fitStage(root.firstElementChild);
                const wrap=root.firstElementChild,paper=wrap.firstElementChild;
                return {scale:Number(wrap.style.getPropertyValue('--cv-fit-scale')),width:paper.offsetWidth,height:paper.offsetHeight};
            };
            const review=measure(false),print=measure(true);
            if(JSON.stringify(review)!==JSON.stringify(print))throw Error(template.id+' long='+long+' review/print differs');
            count++;
        }
        return count+' short/long template combinations match review and PDF page fitting.';
    },{source,templates}));
}finally{await browser.close();}
