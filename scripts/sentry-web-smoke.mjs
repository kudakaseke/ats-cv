import { spawn } from 'node:child_process';
import { once } from 'node:events';
import net from 'node:net';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { chromium, expect } from '@playwright/test';

const reserve = net.createServer();
await new Promise(resolve => reserve.listen(0, '127.0.0.1', resolve));
const port = reserve.address().port;
await new Promise(resolve => reserve.close(resolve));
const token = crypto.randomBytes(16).toString('hex');
const base = `http://127.0.0.1:${port}`;
const child = spawn('php', ['-S', `127.0.0.1:${port}`, 'scripts/sentry-web-probe.php'], {
    env: { ...process.env, SENTRY_WEB_PROBE_TOKEN: token }, windowsHide: true, stdio: 'ignore',
});
let browser;
try {
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
        try { ready = (await fetch(base + '/__probe_ready')).ok; } catch { /* Server starting. */ }
        if (ready) break;
        if (child.exitCode !== null) throw new Error('Isolated probe server failed to start.');
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (!ready) throw new Error('Probe server did not become ready.');
    browser = await chromium.launch({ channel: 'chrome' });
    const page = await browser.newPage();
    const response = await page.goto(base + '/__sentry_probe/' + token);
    expect(response.status()).toBe(500);
    const headers = response.headers();
    expect(headers['x-error-reference']).toMatch(/^[a-f0-9-]{36}$/i);
    expect(headers['x-probe-sentry-event']).toMatch(/^[a-f0-9]{32}$/i);
    expect(headers['x-probe-sentry-status']).toBe('SUCCESS');
    expect(await page.locator('body').textContent()).not.toContain('Synthetic browser verification');
    const result = { at: new Date().toISOString(), http_status: response.status(),
        reference: headers['x-error-reference'], sentry_event: headers['x-probe-sentry-event'],
        sentry_transport: headers['x-probe-sentry-status'], route: 'diagnostics.sentry.web' };
    fs.writeFileSync('storage/app/private/sentry-web-result.json', JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result));
    console.log('PASS: real web exception, safe 500 page, error-reference correlation and Sentry transport acceptance.');
} finally {
    if (browser) await browser.close();
    const stopped = once(child, 'exit');
    child.kill();
    await Promise.race([stopped, new Promise(resolve => setTimeout(resolve, 2000))]);
}
