import fs from 'node:fs';
import { chromium } from '@playwright/test';
const [input, output, layoutPath, format, channel] = process.argv.slice(2);
const browser = await chromium.launch({ channel: channel || 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
    await page.route('**/*', route => route.abort());
    await page.setContent(fs.readFileSync(input, 'utf8'), { waitUntil: 'networkidle' });
    await page.waitForSelector('.editor-paper-wrap .cv-paper');
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(async () => {
        await document.fonts.ready;
        const builder = window.Alpine.$data(document.querySelector('[x-data]'));
        await builder.$nextTick();
        builder.fitPrintedPage();
    });
    if (format === 'pdf') {
        await page.pdf({ path: output, printBackground: true, preferCSSPageSize: true });
    } else {
        const document = await page.evaluate(() => {
            const paper = document.querySelector('.cv-paper');
            const rootRect = paper.getBoundingClientRect();
            const scale = rootRect.width / paper.offsetWidth;
            const hex = color => {
                const channels = color.match(/[\d.]+/g)?.map(Number);
                if (!channels || channels.length < 3 || channels[3] === 0) return null;
                const alpha = channels[3] ?? 1;
                return channels.slice(0, 3).map(c => Math.round(c * alpha + 255 * (1 - alpha)).toString(16).padStart(2, '0')).join('').toUpperCase();
            };
            const style = el => {
                const css = getComputedStyle(el);
                return { font: css.fontFamily.split(',')[0].replace(/["']/g, ''), size: parseFloat(css.fontSize), bold: Number(css.fontWeight) >= 600, italic: css.fontStyle === 'italic', color: hex(css.color), background: hex(css.backgroundColor), align: css.textAlign === 'start' ? 'left' : css.textAlign, line: parseFloat(css.lineHeight) || parseFloat(css.fontSize) * 1.2, before: parseFloat(css.marginTop) || 0, after: (parseFloat(css.marginBottom) || 0) + (parseFloat(css.paddingBottom) || 0), padding: ['Top', 'Right', 'Bottom', 'Left'].map(side => parseFloat(css['padding' + side]) || 0), border: parseFloat(css.borderBottomWidth) > 0 && css.borderBottomStyle !== 'none' ? hex(css.borderBottomColor) : null, uppercase: css.textTransform === 'uppercase' };
            };
            const runs = el => {
                const result = [];
                const walk = node => {
                    if (node.nodeType === Node.TEXT_NODE) {
                        const css = style(node.parentElement);
                        let text = node.textContent;
                        if (css.uppercase) text = text.toUpperCase();
                        if (text) result.push({ text, style: css });
                    } else if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.tagName === 'BR') result.push({ text: '\n', style: style(node) });
                        else if (!node.matches('.cv-language-level')) node.childNodes.forEach(walk);
                    }
                };
                el.childNodes.forEach(walk);
                return result;
            };
            const children = el => Array.from(el.children).flatMap(child => getComputedStyle(child).display === 'contents' ? children(child) : [child]).filter(child => getComputedStyle(child).display !== 'none');
            const paragraph = (el, content = runs(el)) => ({ type: 'paragraph', style: style(el), runs: content, heading: /^H[234]$/.test(el.tagName), bullet: el.tagName === 'LI' });
            const renderChildren = el => Array.from(el.childNodes).flatMap(node => {
                if (node.nodeType === Node.ELEMENT_NODE) return render(node);
                return node.nodeType === Node.TEXT_NODE && node.textContent.trim() ? [{ type: 'paragraph', style: style(el), runs: [{ text: node.textContent, style: style(el) }] }] : [];
            });
            const columns = (el, elements) => {
                const rect = el.getBoundingClientRect();
                const groups = [];
                for (const child of elements) {
                    const x = child.getBoundingClientRect().left;
                    let group = groups.find(group => Math.abs(group.x - x) < 2);
                    if (!group) { group = { x, elements: [] }; groups.push(group); }
                    group.elements.push(child);
                }
                groups.sort((a, b) => a.x - b.x);
                return [{ type: 'table', cells: groups.map((group, index) => {
                    const first = group.elements[0];
                    const css = style(first);
                    const width = (groups[index + 1]?.x ?? rect.right) - group.x;
                    return { width: Math.max(1, width), style: css, blocks: group.elements.flatMap(child => render(child, group.elements.length === 1 || child.matches('.cv-header,.cv-side'))) };
                }) }];
            };
            const render = (el, skipBox = false) => {
                if (getComputedStyle(el).display === 'none') return [];
                const css = style(el);
                if (el.matches('.cv-skills,.cv-contact')) {
                    const content = [];
                    Array.from(el.children).forEach((child, index) => { if (index) content.push({ text: el.matches('.cv-contact') ? ' | ' : ', ', style: css }); content.push(...runs(child)); });
                    return content.length ? [paragraph(el, content)] : [];
                }
                if (el.matches('.cv-language')) {
                    const name = el.querySelector('h4');
                    const level = el.querySelector('p');
                    return [paragraph(el, [{ text: [name?.textContent, level?.textContent].filter(Boolean).join(' - '), style: css }])];
                }
                if (/^(P|H2|H3|H4|SMALL|B|STRONG|LI)$/.test(el.tagName) || el.matches('.cv-role')) return [paragraph(el)];
                const elements = children(el);
                const display = getComputedStyle(el).display;
                if ((display === 'grid' && getComputedStyle(el).gridTemplateColumns.split(' ').length > 1) || (display === 'flex' && el.matches('.ats-entry-heading,.ats-entry-subheading'))) return columns(el, elements);
                if (!skipBox && css.background && css.background !== 'FFFFFF') return [{ type: 'table', cells: [{ width: el.getBoundingClientRect().width, style: css, blocks: render(el, true) }] }];
                return renderChildren(el);
            };
            const builder = window.Alpine.$data(document.querySelector('[x-data]'));
            const page = builder.pageDimensions();
            const padding = style(paper).padding.map(value => value * scale);
            return { scale, page: { width: page.width, height: page.height, padding }, blocks: render(paper, true) };
        });
        fs.writeFileSync(layoutPath, JSON.stringify(document));
    }
} finally {
    await browser.close();
}
