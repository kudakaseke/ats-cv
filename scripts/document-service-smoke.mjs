import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { createDocumentServer } from '../services/document-service/server.mjs';

const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ats-service-smoke-'));
const key = crypto.randomBytes(32).toString('hex');
const tempRoot = path.join(root, 'tmp');
const server = await createDocumentServer({ key, nonceDir: path.join(root, 'nonces'), tempRoot, channel: process.platform === 'win32' ? 'chrome' : 'chromium' });
try {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const status = await new Promise((resolve, reject) => {
        const child = spawn('php', ['scripts/document-service-smoke.php'], { env: { ...process.env,
            SMOKE_DOCUMENT_URL: `http://127.0.0.1:${server.address().port}`, SMOKE_DOCUMENT_KEY: key }, windowsHide: true, stdio: 'inherit' });
        child.once('error', reject);
        child.once('exit', resolve);
    });
    if (status !== 0) throw new Error('Document integration failed.');
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
    if ((await fs.readdir(tempRoot)).length) throw new Error('Temporary document files remained.');
    console.log('PASS: service temporary documents removed.');
} finally {
    if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
    if (path.dirname(root) === path.resolve(os.tmpdir()) && path.basename(root).startsWith('ats-service-smoke-')) await fs.rm(root, { recursive: true, force: true });
}
