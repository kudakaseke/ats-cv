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
});

test('job description step needs no industry selection', () => {
    const context = { window: {}, crypto: { getRandomValues: bytes => webcrypto.getRandomValues(bytes) } };
    runInNewContext(readFileSync(new URL('../resources/js/cv-checker.js', import.meta.url), 'utf8'), context);
    const checker = context.window.cvChecker({});
    checker.job = 'Agricultural Extension Officer: crop production and farmer training.';
    checker.nextJob();
    assert.equal(checker.step, 2);
    assert.equal(checker.error, '');
});

test('background scanning submits once and polls until the result is ready', async () => {
    const context = { window: {}, setTimeout: callback => callback() };
    runInNewContext(readFileSync(new URL('../resources/js/cv-checker.js', import.meta.url), 'utf8'), context);
    const checker = context.window.cvChecker({});
    const calls = [];
    checker.request = async (url, options) => {
        calls.push([url, options.method]);
        if (options.method === 'POST') return { status: 'processing', status_url: '/checker/operations/12' };
        return calls.length === 2 ? { status: 'processing' } : { ai_analysis: { summary: 'Done' } };
    };
    const result = await checker.aiRequest('analyse', '/checker/scan', { method: 'POST' });
    assert.equal(result.ai_analysis.summary, 'Done');
    assert.deepEqual(calls, [['/checker/scan', 'POST'], ['/checker/operations/12', 'GET'], ['/checker/operations/12', 'GET']]);
    assert.equal(checker.pendingUrl, null);
});

test('retry after a lost poll resumes the paid job instead of submitting another', async () => {
    const context = { window: {}, setTimeout: callback => callback() };
    runInNewContext(readFileSync(new URL('../resources/js/cv-checker.js', import.meta.url), 'utf8'), context);
    const checker = context.window.cvChecker({});
    let posts = 0;
    let firstPoll = true;
    checker.request = async (url, options) => {
        if (options.method === 'POST') {
            posts++;
            return { status: 'processing', status_url: '/checker/operations/12' };
        }
        if (firstPoll) { firstPoll = false; throw new Error('Connection lost'); }
        return { ai_analysis: { summary: 'Done' } };
    };
    await assert.rejects(checker.aiRequest('analyse', '/checker/scan', { method: 'POST' }), /Connection lost/);
    assert.equal(checker.pendingUrl, '/checker/operations/12');
    const result = await checker.aiRequest('analyse', '/checker/scan', { method: 'POST' });
    assert.equal(result.ai_analysis.summary, 'Done');
    assert.equal(posts, 1);
});
