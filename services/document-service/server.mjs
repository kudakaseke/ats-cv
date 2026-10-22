import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const hash = body => crypto.createHash('sha256').update(body).digest('hex');
const mac = (key, text) => crypto.createHmac('sha256', key).update(text).digest('hex');
const equal = (a, b) => /^[a-f0-9]{64}$/i.test(a || '') && /^[a-f0-9]{64}$/i.test(b || '')
    && crypto.timingSafeEqual(Buffer.from(a, 'hex'), Buffer.from(b, 'hex'));

export async function createDocumentServer(options = {}) {
    const key = options.key ?? process.env.DOCUMENT_SERVICE_KEY;
    if (!/^[a-f0-9]{64}$/i.test(key || '')) throw new Error('A 32-byte hexadecimal DOCUMENT_SERVICE_KEY is required.');
    const nonceDir = options.nonceDir ?? process.env.DOCUMENT_NONCE_DIR ?? path.join(os.tmpdir(), 'ats-document-nonces');
    const tempRoot = path.resolve(options.tempRoot ?? process.env.DOCUMENT_TEMP_DIR ?? os.tmpdir());
    const maxConcurrent = Number(options.maxConcurrent ?? process.env.DOCUMENT_MAX_CONCURRENT ?? 2);
    if (!Number.isInteger(maxConcurrent) || maxConcurrent < 1 || maxConcurrent > 8) throw new Error('Invalid worker concurrency.');
    await fs.mkdir(nonceDir, { recursive: true, mode: 0o700 });
    await fs.mkdir(tempRoot, { recursive: true, mode: 0o700 });
    const env = {};
    for (const name of ['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'SystemDrive', 'ComSpec', 'COMSPEC', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'HOME', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA', 'ProgramFiles', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'HOMEDRIVE', 'PLAYWRIGHT_BROWSERS_PATH']) {
        if (process.env[name]) env[name] = process.env[name];
    }
    env.DOCUMENT_RENDER_SANDBOX = process.platform === 'linux' ? '1' : '0';
    env.DOCUMENT_SERVICE_LIMITS = '1';
    const python = options.python ?? process.env.DOCUMENT_PYTHON_BINARY ?? (process.platform === 'win32' ? path.join(root, '.venv-ats/Scripts/python.exe') : '/opt/documents-venv/bin/python');
    const php = options.php ?? process.env.DOCUMENT_PHP_BINARY ?? 'php';
    const channel = options.channel ?? process.env.DOCUMENT_BROWSER_CHANNEL ?? 'chromium';
    let active = 0;
    let healthUntil = 0;
    const children = new Set();

    function run(command, args, seconds, signal) {
        return new Promise((resolve, reject) => {
            const child = spawn(command, args, { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'], signal });
            children.add(child);
            const chunks = [];
            let size = 0;
            let killed;
            const timer = setTimeout(() => {
                child.kill('SIGTERM');
                killed = setTimeout(() => child.kill('SIGKILL'), 3000);
                killed.unref();
            }, seconds * 1000);
            child.stdout.on('data', chunk => {
                size += chunk.length;
                if (size > 1024 * 1024) child.kill('SIGTERM');
                else chunks.push(chunk);
            });
            let failed = false;
            child.once('error', () => { failed = true; });
            child.once('close', code => {
                clearTimeout(timer);
                if (killed) clearTimeout(killed);
                children.delete(child);
                if (!failed && code === 0 && size <= 1024 * 1024) resolve(Buffer.concat(chunks).toString('utf8'));
                else reject(new Error('Document tool failed.'));
            });
        });
    }

    const server = http.createServer(async (req, res) => {
        res.setHeader('Cache-Control', 'no-store');
        res.setHeader('X-Content-Type-Options', 'nosniff');
        if (req.method === 'GET' && req.url === '/healthz') {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end('{"running":true}');
            return;
        }
        const nonce = req.headers['x-document-nonce'];
        const reply = (status, value, contentType = 'application/json') => {
            if (res.destroyed) return;
            const bytes = Buffer.isBuffer(value) ? value : Buffer.from(JSON.stringify(value));
            if (typeof nonce === 'string' && /^[a-f0-9-]{36}$/i.test(nonce)) {
                res.setHeader('X-Document-Response-Signature', mac(key, `response\n${nonce}\n${status}\n${hash(bytes)}`));
            }
            if (status === 503) res.setHeader('Retry-After', '10');
            res.writeHead(status, { 'Content-Type': contentType, 'Content-Length': bytes.length });
            res.end(bytes);
        };
        if (req.method !== 'POST' || !['/v1/extract', '/v1/render', '/v1/health'].includes(req.url)) {
            reply(404, { error: 'Not found.' }); req.resume(); return;
        }
        const timestamp = req.headers['x-document-timestamp'];
        const contentHash = req.headers['x-document-content-sha256'];
        if (typeof timestamp !== 'string' || !/^\d{10}$/.test(timestamp)
            || Math.abs(Date.now() / 1000 - Number(timestamp)) > 300
            || typeof nonce !== 'string' || !/^[a-f0-9-]{36}$/i.test(nonce)
            || typeof contentHash !== 'string' || !/^[a-f0-9]{64}$/i.test(contentHash) || !equal(req.headers['x-document-signature'], mac(key, `POST\n${req.url}\n${timestamp}\n${nonce}\n${contentHash}`))) {
            reply(401, { error: 'Authentication required.' }); req.resume(); return;
        }
        try {
            const handle = await fs.open(path.join(nonceDir, hash(nonce)), 'wx', 0o600);
            await handle.close();
        } catch (error) {
            reply(error.code === 'EEXIST' ? 409 : 503, { error: 'Request could not be accepted.' }); req.resume(); return;
        }
        if (active >= maxConcurrent) {
            reply(503, { error: 'Document service is busy. Retry shortly.' }); req.resume(); return;
        }
        active++;
        const controller = new AbortController();
        req.once('aborted', () => controller.abort());
        res.once('close', () => { if (!res.writableEnded) controller.abort(); });
        let directory;
        try {
            if (!/^application\/json(?:;|$)/i.test(req.headers['content-type'] || '')) {
                reply(415, { error: 'JSON required.' }); req.resume(); return;
            }
            if (Number(req.headers['content-length'] || 0) > 8 * 1024 * 1024) {
                reply(413, { error: 'Request too large.' }); req.resume(); return;
            }
            const chunks = [];
            let size = 0;
            for await (const chunk of req) {
                size += chunk.length;
                if (size > 8 * 1024 * 1024) { reply(413, { error: 'Request too large.' }); return; }
                chunks.push(chunk);
            }
            const body = Buffer.concat(chunks);
            if (!equal(hash(body), contentHash)) { reply(401, { error: 'Content authentication failed.' }); return; }
            let data;
            try { data = JSON.parse(body.toString('utf8')); } catch { reply(400, { error: 'Invalid JSON.' }); return; }
            if (!data || Array.isArray(data) || typeof data !== 'object') { reply(400, { error: 'Invalid request.' }); return; }
            if (req.url === '/v1/health') {
                if (Date.now() > healthUntil) {
                    await run(python, ['-c', 'import pymupdf'], 15, controller.signal);
                    await run(php, ['-r', 'exit(0);'], 10, controller.signal);
                    await run(process.execPath, ['services/document-service/health.mjs', channel], 20, controller.signal);
                    healthUntil = Date.now() + 60000;
                }
                reply(200, { ready: true, protocol: 1, capabilities: ['extract', 'pdf', 'word'] }); return;
            }
            directory = await fs.mkdtemp(path.join(tempRoot, 'ats-document-'));
            await fs.chmod(directory, 0o700);
            if (req.url === '/v1/extract') {
                if (typeof data.pdf !== 'string' || !/^[A-Za-z0-9+/]*={0,2}$/.test(data.pdf) || data.pdf.length > 7 * 1024 * 1024) {
                    reply(422, { error: 'Invalid PDF.' }); return;
                }
                const pdf = Buffer.from(data.pdf, 'base64');
                if (pdf.length > 5 * 1024 * 1024 || pdf.subarray(0, 5).toString() !== '%PDF-') {
                    reply(422, { error: 'Invalid PDF.' }); return;
                }
                const input = path.join(directory, 'input.pdf');
                await fs.writeFile(input, pdf, { mode: 0o600 });
                let result;
                try { result = JSON.parse(await run(python, ['scripts/extract-cv-pdf.py', input], 20, controller.signal)); }
                catch { reply(422, { error: 'This PDF could not be read.' }); return; }
                if (typeof result.text !== 'string' || !result.text.trim() || [...result.text].length > 20000 || !Number.isInteger(result.pages) || result.pages < 1 || result.pages > 100) {
                    reply(422, { error: 'This PDF could not be read.' }); return;
                }
                reply(200, result); return;
            }
            if (typeof data.html !== 'string' || Buffer.byteLength(data.html) > 3 * 1024 * 1024 || !['pdf', 'word'].includes(data.format)) {
                reply(422, { error: 'Invalid export request.' }); return;
            }
            const input = path.join(directory, 'input.html');
            const layout = path.join(directory, 'layout.json');
            const output = path.join(directory, data.format === 'pdf' ? 'output.pdf' : 'output.docx');
            await fs.writeFile(input, data.html, { mode: 0o600 });
            await run(process.execPath, ['scripts/export-cv.mjs', input, output, layout, data.format, channel], 60, controller.signal);
            if (data.format === 'word') await run(php, ['scripts/write-cv-word.php', layout, output], 15, controller.signal);
            const info = await fs.stat(output);
            if (info.size === 0 || info.size > 20 * 1024 * 1024) throw new Error('Invalid output size.');
            reply(200, await fs.readFile(output), data.format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        } catch {
            // Never log HTML, PDF bytes, CV text, signatures, keys or tool stderr.
            reply(503, { error: 'Document processing is temporarily unavailable.' });
        } finally {
            try {
                // Verify the resolved recursive-delete target stays in our temp root.
                if (directory && path.dirname(path.resolve(directory)) === tempRoot && path.basename(directory).startsWith('ats-document-')) {
                    await fs.rm(directory, { recursive: true, force: true });
                }
            } catch { console.error('Temporary document cleanup failed.'); }
            finally { active--; }
        }
    });
    server.requestTimeout = 75000;
    server.headersTimeout = 10000;
    server.keepAliveTimeout = 5000;
    server.maxConnections = 128;
    const cleanup = setInterval(async () => {
        try {
            for (const name of await fs.readdir(nonceDir)) {
                if (!/^[a-f0-9]{64}$/.test(name)) continue;
                const file = path.join(nonceDir, name);
                if ((await fs.stat(file)).mtimeMs < Date.now() - 900000) await fs.unlink(file);
            }
            for (const name of await fs.readdir(tempRoot)) {
                if (!/^ats-document-[A-Za-z0-9]+$/.test(name)) continue;
                const target = path.resolve(tempRoot, name);
                const info = await fs.lstat(target);
                // Only our direct, non-symlink directories older than any request can be removed.
                if (path.dirname(target) === tempRoot && info.isDirectory() && !info.isSymbolicLink()
                    && info.mtimeMs < Date.now() - 3600000) await fs.rm(target, { recursive: true, force: true });
            }
        } catch { /* Try again at the next cleanup; no sensitive paths are logged. */ }
    }, 60000);
    cleanup.unref();
    server.on('close', () => { clearInterval(cleanup); for (const child of children) child.kill('SIGTERM'); });
    return server;
}

if (path.resolve(process.argv[1] || '') === fileURLToPath(import.meta.url)) {
    const server = await createDocumentServer();
    server.listen(Number(process.env.PORT || 3100), process.env.DOCUMENT_BIND || '127.0.0.1', () => console.log('Document service listening.'));
    process.once('SIGTERM', () => server.close(() => process.exit(0)));
}
