import fs from 'node:fs';
import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';

const browser = await chromium.launch({channel: process.env.BROWSER_CHANNEL || 'chrome', headless:true});
try {
    const page = await browser.newPage({viewport:{width:1100,height:1300}});
    await page.setContent(`<style>*{box-sizing:border-box}body{margin:0}${fs.readFileSync('resources/css/cv-paper.css','utf8')}</style><main id="preview"></main>`);
    await page.addScriptTag({content:fs.readFileSync('resources/js/cv-builder.js','utf8').replace("import Alpine from 'alpinejs';", 'const Alpine={data:(_,factory)=>window.builderFactory=factory};')});
    const fixtures = [
        {name:'software', heading:'TECHNICAL SKILLS', skills:'Programming: Python, PHP, JavaScript, SQL, HTML5, CSS3\nFrameworks: Laravel, Machine Learning (Scikit-Learn)\nDatabases: MySQL, MongoDB\nTools: Git, RESTful APIs, Microsoft 365, Microsoft Power BI\nMethods: Agile',labels:['Programming:', 'Frameworks:', 'Databases:', 'Tools:', 'Methods:']},
        {name:'accounting', heading:'Specialized Skills', skills:'Technical: Sage Intacct (Advanced), Oracle, Dynamics GP, Power BI, Tableau, SQL, Python\nCompliance: US GAAP, Federal Contract Reporting, Audit Management\nLeadership: Cross-functional Team Development, KPI-Driven Supervision, Strategic Planning',labels:['Technical:', 'Compliance:', 'Leadership:']},
    ];
    for (const fixture of fixtures) {
        for (const mode of ['edit','review']) {
            const result = await page.evaluate(({fixture,mode})=>{
                const template={id:'tailored-reference',layout:'ats-professional',font:'sans',category:'ATS Pass'};
                const cv=window.builderFactory({draft:{step:4,data:{template:template.id,name:'Example Applicant',skills:fixture.skills,section_titles:{skills:fixture.heading}}},templates:[template]});
                document.querySelector('#preview').innerHTML=mode==='edit'?cv.editablePreview(template):cv.reviewPreview(template);
                const group=document.querySelector('[data-section="skills"] .cv-skills-grouped');
                const rows=[...group.children];
                const box=group.getBoundingClientRect();
                return {display:getComputedStyle(group).display,width:box.width,labels:rows.map(row=>row.querySelector('strong')?.textContent),rows:rows.map(row=>({y:row.getBoundingClientRect().y,x:row.getBoundingClientRect().x,width:row.getBoundingClientRect().width,height:row.getBoundingClientRect().height,font:parseFloat(getComputedStyle(row).fontSize),family:getComputedStyle(row).fontFamily,bold:getComputedStyle(row.querySelector('strong')).fontWeight})),sectionWidth:group.closest('section').getBoundingClientRect().width};
            },{fixture,mode});
            assert.equal(result.display,'block',`${fixture.name} ${mode}: category rows must stack vertically`);
            assert.deepEqual(result.labels,fixture.labels);
            assert.ok(result.width>650);
            for (let i=0;i<result.rows.length;i++) {
                const row=result.rows[i];
                assert.ok(row.width>=result.width-1,'each row spans the section width');
                assert.ok(Number(row.bold)>=700); assert.match(row.family, /Arial/);
                assert.ok(row.height<row.font*1.5,'reference rows should each fit on one line');
                if(i) assert.ok(row.y>=result.rows[i-1].y+result.rows[i-1].height-1,'category rows cannot share a grid row');
            }
            if(mode==='review') await page.locator('[data-section="skills"]').screenshot({path:`storage/app/skills-${fixture.name}-preview.png`});
            console.log(`${fixture.name} ${mode}: full-width category rows verified`);
        }
    }
} finally {await browser.close();}
