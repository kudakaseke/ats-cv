import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { createDocumentServer } from './server.mjs';

const key = crypto.randomBytes(32).toString('hex');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const mac = text => crypto.createHmac('sha256', key).update(text).digest('hex');
function signed(route, body, time = Math.floor(Date.now() / 1000)) {
    const nonce = crypto.randomUUID();
    return { 'Content-Type': 'application/json', 'X-Document-Timestamp': String(time),
        'X-Document-Nonce': nonce, 'X-Document-Content-SHA256': hash(body),
        'X-Document-Signature': mac(`POST\n${route}\n${time}\n${nonce}\n${hash(body)}`) };
}

test('document service authenticates content, rejects replays and cleans private temporary files', async () => {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ats-service-test-'));
    const tempRoot = path.join(root, 'tmp');
    const options = { key, nonceDir: path.join(root, 'nonces'), tempRoot };
    let server;
    async function start() {
        server = await createDocumentServer(options);
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        return `http://127.0.0.1:${server.address().port}`;
    }
    async function close() {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
    try {
        let url = await start();
        assert.equal((await fetch(url + '/healthz')).status, 200);
        assert.equal((await fetch(url + '/v1/extract', { method: 'POST', body: '{}' })).status, 401);
        assert.equal((await fetch(url + '/not-a-route')).status, 404);
        const route = '/v1/extract';
        const body = JSON.stringify({ pdf: Buffer.from('not a PDF').toString('base64') });
        assert.equal((await fetch(url + route, { method: 'POST', body, headers: signed(route, body, Math.floor(Date.now() / 1000) - 600) })).status, 401);
        const tampered = signed(route, '{}');
        assert.equal((await fetch(url + route, { method: 'POST', body, headers: tampered })).status, 401);
        const headers = signed(route, body);
        const result = await fetch(url + route, { method: 'POST', body, headers });
        const bytes = Buffer.from(await result.arrayBuffer());
        assert.equal(result.status, 422);
        assert.equal(result.headers.get('X-Document-Response-Signature'), mac(`response\n${headers['X-Document-Nonce']}\n422\n${hash(bytes)}`));
        assert.equal((await fetch(url + route, { method: 'POST', body, headers })).status, 409);
        await close();
        url = await start();
        assert.equal((await fetch(url + route, { method: 'POST', body, headers })).status, 409, 'replay protection survives restart');
        const invalidJson = '{';
        assert.equal((await fetch(url + route, { method: 'POST', body: invalidJson, headers: signed(route, invalidJson) })).status, 400);
        const wrongType = signed(route, '{}');
        wrongType['Content-Type'] = 'text/plain';
        assert.equal((await fetch(url + route, { method: 'POST', body: '{}', headers: wrongType })).status, 415);
        const oversized = 'x'.repeat(8 * 1024 * 1024 + 1);
        assert.equal((await fetch(url + route, { method: 'POST', body: oversized, headers: signed(route, oversized) })).status, 413);
        await close();
        server = null;
        assert.deepEqual(await fs.readdir(tempRoot), [], 'temporary CV files removed');
    } finally {
        if (server) await close();
        if (path.dirname(root) === path.resolve(os.tmpdir()) && path.basename(root).startsWith('ats-service-test-')) await fs.rm(root, { recursive: true, force: true });
    }
});

test('concurrency limit rejects excess requests and recovers after cancellation', async () => {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ats-service-test-'));
    const server = await createDocumentServer({ key, nonceDir: path.join(root, 'nonces'), tempRoot: path.join(root, 'tmp'), maxConcurrent: 1 });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    let pending;
    try {
        pending = http.request(url + '/v1/extract', { method: 'POST', headers: signed('/v1/extract', '{}') });
        pending.on('error', () => {});
        pending.write('{'); // Hold one authenticated request open while its body is arriving.
        await new Promise(resolve => setTimeout(resolve, 100));
        const result = await fetch(url + '/v1/extract', { method: 'POST', body: '{}', headers: signed('/v1/extract', '{}') });
        assert.equal(result.status, 503);
        assert.equal(result.headers.get('Retry-After'), '10');
        pending.destroy();
        await new Promise(resolve => setTimeout(resolve, 100));
        assert.equal((await fetch(url + '/v1/extract', { method: 'POST', body: '{}', headers: signed('/v1/extract', '{}') })).status, 422);
    } finally {
        pending?.destroy();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
        if (path.dirname(root) === path.resolve(os.tmpdir()) && path.basename(root).startsWith('ats-service-test-')) await fs.rm(root, { recursive: true, force: true });
    }
});
