import assert from 'node:assert/strict';
import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from '@playwright/test';

const read = name => JSON.parse(execFileSync('php', ['-r', `echo json_encode(require 'config/${name}.php');`], {encoding:'utf8'}));
const templates = read('cv_templates'), departments = read('cv_departments');
const source = fs.readFileSync('resources/js/cv-builder.js', 'utf8').replace("import Alpine from 'alpinejs';", '');
const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
const css = fs.readFileSync('public/build/' + manifest['resources/css/app.css'].file, 'utf8');
const browser = await chromium.launch({channel:'chrome', headless:true});
try {
    const page = await browser.newPage({viewport:{width:900,height:1250}});
    await page.setContent('<style>' + css + '</style><main></main>');
    const results = await page.evaluate(({source, templates, departments}) => {
        let factory;
        new Function('Alpine', source)({data:(_name, fn) => factory = fn});
        const cv = factory({draft:{}, templates, departments});
        const results = [];
        for (const department of cv.departmentExamples) {
            const template = department.template;
            document.querySelector('main').innerHTML = cv.departmentPreview(department);
            const paper = document.querySelector('.cv-paper');
            if (!paper.classList.contains('cv-department-ats')) throw Error(department.title + ': wrong design');
            if (paper.querySelector('table, img, svg')) throw Error(department.title + ': non-text content');
            if (getComputedStyle(paper.querySelector('.cv-side')).display !== 'none') throw Error('Unexpected sidebar');
            const ids = [...paper.querySelectorAll('.cv-main > .cv-section')].map(el => el.dataset.section);
            const expected = template.section_order.filter(id => id !== 'custom_sections' || template.department_profile.project);
            if (JSON.stringify(ids) !== JSON.stringify(expected)) throw Error(department.title + ': incorrect order ' + ids);
            const project = [...paper.querySelectorAll('h3')].some(el => /project/i.test(el.textContent));
            if (project !== ['engineering', 'it'].includes(department.id)) throw Error(department.title + ': inappropriate projects');
            const bounds = paper.getBoundingClientRect();
            const last = paper.querySelector('.cv-main > .cv-section:last-child').getBoundingClientRect();
            if (last.bottom > bounds.bottom - 25) throw Error(department.title + ': content overflows page');
            for (const section of paper.querySelectorAll('.cv-section')) {
                if (getComputedStyle(section).marginBottom !== '18px') throw Error(department.title + ': inconsistent section spacing');
            }
            for (const skill of paper.querySelectorAll('.cv-skills > span')) {
                if (parseFloat(getComputedStyle(skill).borderBottomWidth) !== 0) throw Error('Skills should not be underlined');
            }
            // Existing content, custom headings and section placement must survive review.
            cv.data = {...cv.data, template:template.id, name:'Real Person', summary:'My own summary', skills:'Actual skill',
                section_titles:{skills:'My skills'}, custom_sections:[{title:'My voluntary work', content:'My own contribution'}],
                section_positions:[{id:'custom_sections', region:'main', after:'education'}]};
            const review = new DOMParser().parseFromString(cv.reviewPreview(template), 'text/html');
            if (!review.body.textContent.includes('My own contribution') || !review.body.textContent.includes('My skills')) throw Error('User content lost');
            if (review.body.textContent.includes('Example Organisation')) throw Error('Gallery data leaked into draft');
            results.push(department.title);
        }
        document.querySelector('main').innerHTML = cv.departmentPreview(cv.departmentExamples[0]);
        return results;
    }, {source, templates, departments});
    assert.equal(results.length, 11);
    await page.screenshot({path:'storage/app/department-ats-preview.png', fullPage:true});
    console.log('11 departments passed: ATS layout, section order, relevant projects, page spacing, preserved user content and headings.');
} finally {
    await browser.close();
}
