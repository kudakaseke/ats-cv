import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { signInTestUser, removeTestUser } from './browser-auth.mjs';

const root = path.resolve('storage/app/private');
fs.mkdirSync(root, { recursive: true });
const prefix = `upload-audit-${Date.now()}`;
const files = ['txt', 'docx', 'pdf'].map(extension => path.join(root, `${prefix}.${extension}`));
const python = path.resolve('.venv-ats/Scripts/python.exe');
execFileSync(python, ['-c', `import pathlib,sys,zipfile,pymupdf,os
p=pathlib.Path(sys.argv[1]); text='Alex Example\\nSoftware Developer\\nSkills\\nPHP, Laravel, SQL\\nEducation\\nDiploma in Computing'
p.with_suffix('.txt').write_text(text,encoding='utf-8')
with zipfile.ZipFile(p.with_suffix('.docx'),'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('word/document.xml','<w:document xmlns:w="urn:word"><w:body>'+''.join('<w:p><w:r><w:t>'+line+'</w:t></w:r></w:p>' for line in text.splitlines())+'</w:body></w:document>')
 z.writestr('word/media/fixture.bin',os.urandom(2300000))
with pymupdf.open() as d:
 page=d.new_page();page.insert_text((40,40),text);d.save(p.with_suffix('.pdf'))
`, path.join(root, prefix)], { stdio: 'pipe' });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
let fixture;
const errors = [];
page.on('pageerror', error => errors.push(error.message));
try {
    await page.route(/\/checker\/(scan|rewrite)$/, route => route.abort());
    fixture = await signInTestUser(page, process.env.APP_TEST_URL || 'http://127.0.0.1:8000');
    await page.goto((process.env.APP_TEST_URL || 'http://127.0.0.1:8000') + '/workspace/checker');
    await page.getByLabel('Job description', { exact: true }).fill('PHP Developer: PHP, Laravel and SQL');
    await page.getByRole('button', { name: 'Next: Upload resume' }).click();
    for (const file of files) {
        const pending = page.waitForResponse(response => response.url().endsWith('/checker/upload'));
        await page.getByLabel('Resume file', { exact: true }).setInputFiles(file);
        const response = await pending;
        expect(response.status(), `${path.extname(file)} import`).toBe(200);
        await expect(page.locator('.checker-filename')).toHaveText(path.basename(file));
        expect(await page.locator('#checker-resume').inputValue()).toContain('PHP, Laravel, SQL');
    }
    expect(errors).toEqual([]);
    console.log(JSON.stringify({ passed: true, formats: ['TXT', 'DOCX over 2 MB', 'PDF'], paid_api_calls: 0, javascript_errors: errors }));
} finally {
    await browser.close();
    removeTestUser(fixture);
    for (const file of files) if (fs.existsSync(file)) fs.unlinkSync(file);
}
