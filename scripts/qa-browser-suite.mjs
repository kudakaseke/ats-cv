import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import net from 'node:net';

// Own the server and database: never migrate/reset the developer's database.
if (fs.existsSync('bootstrap/cache/config.php')) throw new Error('Clear cached Laravel configuration before running isolated browser QA.');
const database = path.resolve(`storage/app/qa-browser-${randomBytes(6).toString('hex')}.sqlite`);
fs.writeFileSync(database, '');
const listener = net.createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const base = `http://127.0.0.1:${port}`;
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: base, APP_TEST_URL: base,
    CV_DOCUMENT_DRIVER: 'local', APP_KEY: `base64:${randomBytes(32).toString('base64')}`, DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    CACHE_STORE: 'database', CACHE_LIMITER: 'database', SESSION_DRIVER: 'database', MAIL_MAILER: 'array',
    QUEUE_CONNECTION: 'sync', BACKGROUND_QUEUE_CONNECTION: 'sync', QUEUE_PAYMENT_CHECKS: 'false',
    PAYNOW_ENABLED: 'false', PAYNOW_MODE: 'test', PAYNOW_LOCAL_TEST_CREDITS: 'false',
    PAYNOW_INTEGRATION_ID: '', PAYNOW_INTEGRATION_KEY: '', PAYNOW_TEST_INTEGRATION_ID: '', PAYNOW_TEST_INTEGRATION_KEY: '',
    OPENAI_API_KEY: '', GEMINI_API_KEY: '', GOOGLE_CLIENT_ID: '', GOOGLE_CLIENT_SECRET: '', SENTRY_LARAVEL_DSN: '' };
function run(command, args) {
    const result = spawnSync(command, args, { env, stdio: 'inherit', timeout: 600000 });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`${command} ${args.join(' ')} failed (${result.status}).`);
}
let server;
try {
    run('php', ['artisan', 'migrate', '--force']);
    run('php', ['artisan', 'db:seed', '--class=SubscriptionPlanSeeder', '--force']);
    server = spawn('php', ['-S', `127.0.0.1:${port}`, path.resolve('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { env, cwd: path.resolve('public'), stdio: 'ignore' });
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            const response = await fetch(`${base}/up`, { headers: { Connection: 'close' }, signal: AbortSignal.timeout(5000) });
            await response.text();
            if (response.ok) { ready = true; break; }
        } catch { /* Server starting. */ }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (!ready) throw new Error('Isolated QA server failed to start.');
    const supported = ['public-auth-smoke', 'qa-fixes-smoke', 'builder-navigation-smoke', 'builder-edge-smoke', 'builder-save-race-smoke', 'builder-smoke', 'checker-smoke', 'detect-smoke', 'pricing-switch-smoke', 'audit-fixes-smoke'];
    const selected = process.argv.slice(2);
    if (selected.some(script => !supported.includes(script))) throw new Error('Unknown QA script.');
    for (const script of selected.length ? selected : supported) {
        run(process.execPath, [`scripts/${script}.mjs`]);
    }
    console.log('PASS: maintained browser QA suite; isolated database, deterministic providers.');
} finally {
    if (server && server.exitCode === null) {
        const stopped = new Promise(resolve => server.once('exit', resolve));
        server.kill();
        await stopped;
    }
    for (const suffix of ['', '-wal', '-shm', '-journal']) fs.rmSync(database + suffix, { force: true });
}
