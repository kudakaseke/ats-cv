import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const templates = JSON.parse(execFileSync('php', ['-r', "echo json_encode(require 'config/cv_templates.php');"], { encoding: 'utf8' }));
const source = fs.readFileSync('resources/js/cv-builder.js', 'utf8').replace("import Alpine from 'alpinejs';", '');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage();
    await page.setContent(`<style>${fs.readFileSync('resources/css/cv-paper.css', 'utf8')}</style><main></main>`);
    const count = await page.evaluate(({ templates, source }) => {
        let factory;
        new Function('Alpine', source)({ data: (_name, fn) => factory = fn });
        const skills = 'Excel, Reporting, SQL, Planning, Research, Testing, Documentation, Analysis, Budgeting, Communication';
        for (const template of templates) {
            const cv = factory({ draft: { data: { name: 'Example Applicant', template: template.id, skills, core_strengths: skills } }, templates });
            cv.$root = document.querySelector('main');
            for (const editing of [false, true]) {
                cv.$root.innerHTML = editing ? cv.editablePreview(template) : cv.reviewPreview(template);
                for (const section of ['skills', 'core_strengths']) {
                    const grid = cv.$root.querySelector(`[data-section="${section}"] .cv-skills`);
                    if (!grid || grid.children.length !== 10) throw Error(template.id + ': missing items');
                    const style = getComputedStyle(grid);
                    if (style.gridTemplateColumns.split(' ').length !== 5 || style.gridTemplateRows.split(' ').length !== 2) throw Error(template.id + ': expected five columns and two rows');
                    if (grid.scrollWidth > grid.clientWidth + 1) throw Error(template.id + ': grid overflows');
                }
                if (cv.$root.querySelector('[data-section="core_strengths"] h3').textContent !== 'Competencies') throw Error(template.id + ': combined heading remains');
            }
        }
        return templates.length;
    }, { templates, source });
    console.log(`PASS: ${count} templates show ten skills/competencies as five columns and two rows in editing and review.`);
} finally {
    await browser.close();
}
