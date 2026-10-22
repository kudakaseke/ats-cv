import { chromium } from '@playwright/test';
const channel = process.argv[2] || 'chromium';
const browser = await chromium.launch({ ...(channel === 'chromium' ? {} : { channel }), headless: true, chromiumSandbox: process.env.DOCUMENT_RENDER_SANDBOX === '1' });
await browser.close();
