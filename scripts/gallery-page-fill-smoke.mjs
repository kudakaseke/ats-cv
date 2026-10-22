import { chromium, expect } from '@playwright/test';

const browser = await chromium.launch({ channel: 'chrome' });
try {
    const page = await browser.newPage();
    await page.route('https://fonts.googleapis.com/**', route => route.abort());
    for (const width of [1900, 1366, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.goto((process.env.APP_TEST_URL || 'http://127.0.0.1:8000') + '/get-started?templates=1', { waitUntil: 'domcontentloaded' });
        for (const category of ['All', 'By Department']) {
            await page.getByRole('button', { name: category, exact: true }).click();
            await expect(page.locator('.template-preview:visible .cv-paper').first()).toBeVisible();
            // Allow the page-fit ResizeObserver to settle after changing categories.
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const previews = await page.locator('.template-preview:visible').evaluateAll(stages => stages.map(stage => {
                const bounds = stage.getBoundingClientRect();
                const sections = [...stage.querySelectorAll('.cv-section')];
                return {
                    template: stage.querySelector('.cv-paper').dataset.template,
                    fill: (Math.max(...sections.map(section => section.getBoundingClientRect().bottom)) - bounds.top) / bounds.height,
                };
            }));
            expect(previews.length).toBeGreaterThan(0);
            for (const preview of previews) {
                expect(preview.fill, `${preview.template} should show a fuller example`).toBeGreaterThan(.75);
                expect(preview.fill, `${preview.template} must fit one page`).toBeLessThanOrEqual(1.01);
            }
            console.log(`${category}: ${previews.length} complete page previews verified at ${width}px`);
        }
    }
} finally {
    await browser.close();
}
