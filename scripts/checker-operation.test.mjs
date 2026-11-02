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
    checker.job += ' and SQL';
    assert.notEqual(checker.operation('analyse'), first);
});

test('job description proceeds without manual industry selection', () => {
    const context = { window: {} };
    runInNewContext(readFileSync(new URL('../resources/js/cv-checker.js', import.meta.url), 'utf8'), context);
    const checker = context.window.cvChecker({});
    checker.job = 'PHP developer';
    checker.nextJob();
    assert.equal(checker.step, 2);
    assert.equal(checker.error, '');
});
