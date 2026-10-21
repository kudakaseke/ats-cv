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
        for (const template of templates) {
            const content = 'Reporting Tool\nAutomated the monthly reporting process.\nReduced manual administration.\n\nBudget Tracker\nTracked spending against approved budgets.';
            const cv = factory({ draft: { data: { template: template.id, name: 'Alex Example', education: [{ degree: 'Bachelor of Commerce', school: 'Example College', period: '2020 - 2024', grade: 'Upper Second Class (2:1)' }], achievements: [{ title: 'Service Award', description: 'Recognised for reliable customer support.' }], custom_sections: [{ title: 'Projects', content }] } }, templates });
            cv.$root = document.querySelector('main');
            cv.$nextTick = callback => callback?.();
            for (const editing of [false, true]) {
                cv.$root.innerHTML = editing ? cv.editablePreview(template) : cv.reviewPreview(template);
                const project = cv.$root.querySelector('[data-section="custom_sections"]');
                const titles = [...project.querySelectorAll('.cv-item-title strong')].map(node => node.textContent);
                if (JSON.stringify(titles) !== JSON.stringify(['Reporting Tool', 'Budget Tracker'])) throw Error(template.id + ': project titles mixed with descriptions');
                if (!project.querySelector('.cv-item-description')?.textContent.includes('Automated')) throw Error(template.id + ': description missing');
                if (getComputedStyle(project.querySelector('.cv-item-title strong')).fontWeight !== '700') throw Error(template.id + ': title not bold');
                const achievement = cv.$root.querySelector('[data-section="achievements"] .cv-entry');
                const title = achievement.querySelector('h4, strong');
                if (getComputedStyle(title).display !== 'block' || getComputedStyle(title).fontWeight !== '700') throw Error(template.id + ': achievement title inline or not bold');
                if (!cv.$root.querySelector('[data-section="education"]').textContent.includes('Upper Second Class')) throw Error(template.id + ': grade missing');
                if (editing) {
                    const field = project.querySelector('[data-field="custom_sections.0.content"]');
                    cv.commitInline({ target: field });
                    if (cv.data.custom_sections[0].content !== content) throw Error(template.id + ': project editing altered title/description boundaries');
                    if (!cv.$root.querySelector('[data-field="education.0.grade"]')) throw Error(template.id + ': grade cannot be edited');
                }
            }
        }
        return templates.length;
    }, { templates, source });
    console.log(`PASS: ${count} templates preserve bold titles, separate descriptions and editable education grade in editing/review.`);
} finally {
    await browser.close();
}
