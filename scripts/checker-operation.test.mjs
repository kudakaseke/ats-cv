import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { webcrypto } from 'node:crypto';

test('AI operation IDs work without randomUUID and preserve retry identity', () => {
    const context = { window: {}, crypto: { getRandomValues: bytes => webcrypto.getRandomValues(bytes) } };
    runInNewContext(readFileSync(new URL('../resources/js/cv-checker.js', import.meta.url), 'utf8'), context);
    const checker = context.window.cvChecker({});
    checker.job = 'PHP developer';
    checker.resumeText = 'PHP experience';
    const first = checker.operation('analyse');
    assert.match(first, /^[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}$/);
    assert.equal(checker.operation('analyse'), first);
    assert.notEqual(checker.operation('revamp'), first);
    checker.resumeText += ' and Laravel';
    assert.notEqual(checker.operation('analyse'), first);
    const beforeIndustryChange = checker.operation('analyse');
    checker.industry = 'supply-chain';
    assert.notEqual(checker.operation('analyse'), beforeIndustryChange);
});


function transportChecker(response) {
    const context = {
        window: { dispatchEvent() {} },
        crypto: { getRandomValues: bytes => webcrypto.getRandomValues(bytes) },
        fetch: async () => response,
        setInterval, clearInterval,
        CustomEvent: class { constructor(type) { this.type = type; } },
    };
    runInNewContext(readFileSync(new URL('../resources/js/cv-checker.js', import.meta.url), 'utf8'), context);
    const checker = context.window.cvChecker({});
    checker.job = 'PHP developer';
    checker.resumeText = 'PHP experience';
    return checker;
}

test('ambiguous gateway failures preserve the operation ID for a safe cached retry', async () => {
    for (const html of [false, true]) {
        const checker = transportChecker({ status: 503, ok: false,
            headers: { get: () => html ? 'text/html' : 'application/json' },
            json: async () => { if (html) throw new SyntaxError('Proxy HTML'); return { message: 'Gateway unavailable' }; },
        });
        const id = checker.operation('analyse');
        await assert.rejects(checker.request('/scan', { body: JSON.stringify({ operation_id: id }) }));
        assert.equal(checker.operation('analyse'), id);
    }
});

test('only a confirmed failed operation matching the request may get a new ID', async () => {
    let body;
    const checker = transportChecker({ status: 503, ok: false, headers: { get: () => 'application/json' }, json: async () => body });
    const id = checker.operation('analyse');
    const rewrite = checker.operation('revamp');
    body = { message: 'Credits refunded', operation_failed: true, operation_id: id };
    await assert.rejects(checker.request('/scan', { body: JSON.stringify({ operation_id: id }) }));
    assert.notEqual(checker.operation('analyse'), id);
    assert.equal(checker.operation('revamp'), rewrite);
});

test('a failure marker for a different request cannot reset another operation', async () => {
    let body;
    const checker = transportChecker({ status: 503, ok: false, headers: { get: () => 'application/json' }, json: async () => body });
    const id = checker.operation('analyse');
    const rewrite = checker.operation('revamp');
    body = { operation_failed: true, operation_id: rewrite };
    await assert.rejects(checker.request('/scan', { body: JSON.stringify({ operation_id: id }) }));
    assert.equal(checker.operation('analyse'), id);
    assert.equal(checker.operation('revamp'), rewrite);
});

test('malformed successful JSON keeps the retry ID and reports an unexpected response', async () => {
    const checker = transportChecker({ status: 200, ok: true, headers: { get: () => 'application/json' }, json: async () => { throw new SyntaxError('Truncated response'); } });
    const id = checker.operation('analyse');
    await assert.rejects(checker.request('/scan', { body: JSON.stringify({ operation_id: id }) }), /unexpected response/);
    assert.equal(checker.operation('analyse'), id);
});


function dialogRefs() {
    return { scanDialog: { open: false, showModal() { this.open = true; } } };
}

test('incomplete scan JSON is rejected before clearing the charged operation identity', async () => {
    const checker = transportChecker({ status: 200, ok: true, headers: { get: () => 'application/json' }, json: async () => ({}) });
    checker.$refs = dialogRefs();
    checker.$nextTick = callback => { callback?.(); return Promise.resolve(); };
    const id = checker.operation('analyse');
    await checker.scan();
    assert.equal(checker.operation('analyse'), id);
    assert.equal(checker.result, null);
    assert.match(checker.error, /unexpected response/);
    assert.equal(checker.busy, false);
});

test('incomplete rewrite JSON never submits an empty CV and retains the retry identity', async () => {
    const checker = transportChecker({ status: 200, ok: true, headers: { get: () => 'application/json' }, json: async () => ({}) });
    let submitted = false;
    checker.$refs = { ...dialogRefs(), generatedForm: { requestSubmit() { submitted = true; } } };
    checker.$nextTick = callback => { callback?.(); return Promise.resolve(); };
    const id = checker.operation('revamp');
    await checker.fix();
    assert.equal(submitted, false);
    assert.equal(checker.operation('revamp'), id);
    assert.match(checker.error, /unexpected response/);
    assert.equal(checker.busy, false);
});
