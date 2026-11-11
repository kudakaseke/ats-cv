import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

function builder(fetch = async () => ({ ok: true, status: 200, json: async () => ({ saved: true }) }), window = {}) {
    let factory;
    vm.runInNewContext(fs.readFileSync('resources/js/cv-builder.js', 'utf8').replace("import Alpine from 'alpinejs';", ''), {
        Alpine: { data: (_name, value) => { factory = value; } },
        clearTimeout, setTimeout, Promise, fetch, FormData, window,
    });
    const cv = factory({ draft: {}, templates: [], saveUrl: '/draft', finishUrl: '/finish', csrf: 'test' });
    cv.$refs = { finishForm: { requestSubmit: () => window.location?.assign('/finish') } };
    return cv;
}

test('empty optional sections and placeholder text are omitted in every normal layout', () => {
    const cv = builder();
    for (const layout of ['double', 'sidebar', 'single', 'centered', 'banner']) {
        const html = cv.preview({ layout, font: 'sans', accent: '#111' });
        assert.doesNotMatch(html, /Your role|Company name|Date period|Your achievement|Your skills|Course title|Language<|cv-placeholder/);
        assert.doesNotMatch(html, /<section data-section=/);
    }
    cv.data.name = '<img src=x onerror=alert(1)>';
    cv.data.custom_sections = [{ title: '<script>bad</script>', content: '<b>Actual content</b>' }];
    assert.doesNotMatch(cv.preview({ layout: 'single', font: 'sans', accent: '#111' }), /<img|<script>|<b>Actual content/);
});

test('invalid date changes restore the picker and keep the saved period', () => {
    const cv = builder();
    const item = { period: 'Mar 2022 - Jun 2024' };
    const input = { value: '2020-01' };
    assert.equal(cv.setPeriod(item, 'end', input.value, input), false);
    assert.equal(input.value, '2024-06');
    assert.equal(item.period, 'Mar 2022 - Jun 2024');
    assert.match(cv.error, /end month/);
    cv.setPeriod(item, 'end', '2025-01', input);
    assert.equal(item.period, 'Mar 2022 - Jan 2025');
    assert.equal(cv.error, '');
    cv.setPeriod(item, 'start', '10000-01', input);
    assert.equal(item.period, 'Mar 2022 - Jan 2025');
    assert.equal(input.value, '2022-03');
});

test('year-only imports remain unchanged and end-only or ongoing periods can be edited', () => {
    const cv = builder();
    const item = { period: '2018 - 2021' };
    cv.setPeriod(item, 'end', '2022-04');
    assert.equal(item.period, '2018 - Apr 2022');
    const empty = { period: '' };
    cv.setPeriod(empty, 'present', true);
    assert.equal(cv.periodParts(empty.period).present, true);
    cv.setPeriod(empty, 'present', false);
    cv.setPeriod(empty, 'end', '2024-06');
    assert.equal(cv.periodParts(empty.period).end, '2024-06');
    cv.setPeriod(empty, 'start', '2023-03');
    assert.equal(empty.period, 'Mar 2023 - Jun 2024');
});

test('an older save response never reports newer unsaved changes as saved', async () => {
    let resolve;
    const cv = builder(() => new Promise(done => { resolve = done; }));
    const saving = cv.save();
    await new Promise(setImmediate);
    cv.data.name = 'Newer edit';
    resolve({ ok: true, status: 200, json: async () => ({ saved: true }) });
    assert.equal(await saving, true);
    assert.equal(cv.saveState, 'Unsaved changes');
    const latest = cv.save();
    await new Promise(setImmediate);
    resolve({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await latest;
    assert.equal(cv.saveState, 'Draft saved for this session');
});

test('expired sessions, request limits and HTML errors produce useful messages', async () => {
    const cv = builder();
    await assert.rejects(cv.responseData({ status: 419, json: async () => { throw Error(); } }), /session has expired/);
    await assert.rejects(cv.responseData({ status: 429, json: async () => ({}), headers: { get: () => '30' } }), /Wait 30 seconds/);
    await assert.rejects(cv.responseData({ status: 500, ok: false, json: async () => { throw Error(); } }, 'Unable to save.'), /Unable to save/);
});

test('import preserves typed sections and adds detected custom sections', async () => {
    const cv = builder(async () => ({ ok: true, status: 200, json: async () => ({ text: 'Uploaded CV', data: { experience: [{ title: 'Imported role' }], custom_sections: [{ title: 'Projects', content: 'Imported project' }] } }) }));
    cv.data.experience = [{ title: 'Fluent', company: '', period: '', location: '', description: '' }];
    cv.data.custom_sections = [{ title: 'Awards', content: 'Existing award' }];
    cv.go = async step => { cv.step = step; };
    const target = { files: [new File(['content'], 'cv.pdf', { type: 'application/pdf' })], value: 'cv.pdf' };
    await cv.upload({ target });
    assert.equal(cv.data.experience[0].title, 'Fluent');
    assert.equal(cv.data.custom_sections.length, 2);
    assert.equal(cv.data.custom_sections[1].content, 'Imported project');
    assert.equal(target.value, '');
    assert.equal(cv.step, 3);
});

test('leaving the editor waits for the latest save before navigating', async () => {
    let finishRequest;
    const destinations = [];
    const cv = builder(() => new Promise(resolve => { finishRequest = resolve; }), { location: { assign: value => destinations.push(value) } });
    cv.data.name = 'Latest typed name';
    const leaving = cv.leave('/dashboard');
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.deepEqual(destinations, []);
    finishRequest({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await leaving;
    assert.deepEqual(destinations, ['/dashboard']);
});

test('inline editing updates the structured CV and escapes inserted text', () => {
    const cv = builder();
    cv.commitInline({target:{closest:()=>({dataset:{field:'name'}, innerText:'  Real Applicant  '})}});
    assert.equal(cv.data.name, 'Real Applicant');
    cv.commitInline({target:{closest:()=>({dataset:{field:'experience.0.company'}, innerText:'Example Company'})}});
    assert.equal(cv.data.experience[0].company, 'Example Company');
    cv.data.summary = '<script>alert(1)</script>';
    assert.doesNotMatch(cv.inlineField('summary', 'Summary'), /<script>/);
    assert.match(cv.inlineField('summary', 'Summary'), /&lt;script&gt;/);
});

test('on-document controls add and remove structured entries', () => {
    const cv = builder();
    const action = dataset => cv.canvasAction({target:{closest: selector => selector === '[data-canvas-action]' ? {dataset} : null}});
    action({canvasAction:'add',sectionId:'experience'});
    assert.equal(cv.data.experience.length, 2);
    action({canvasAction:'remove',sectionId:'experience',index:'0'});
    assert.equal(cv.data.experience.length, 1);
    action({canvasAction:'add',sectionId:'custom_sections'});
    assert.equal(cv.data.custom_sections[0].title, 'Additional section');
});

test('on-document month pickers update dates and support Present', () => {
    const cv = builder();
    const change = (part, value, checked = false) => cv.changeInlineDate({target:{dataset:{dateSection:'experience',dateIndex:'0',datePart:part},value,checked}});
    change('start', '2022-03');
    change('end', '2024-06');
    assert.equal(cv.data.experience[0].period, 'Mar 2022 - Jun 2024');
    assert.match(cv.inlineDates('experience', 0), /type="month"/);
    assert.match(cv.inlineDates('experience', 0), /value="2022-03"/);
    change('present', '', true);
    assert.equal(cv.data.experience[0].period, 'Mar 2022 - Present');
    assert.match(cv.inlineDates('experience', 0), /disabled/);
    change('present', '', false);
    assert.equal(cv.data.experience[0].period, 'Mar 2022');
    change('end', '2020-01');
    assert.equal(cv.data.experience[0].period, 'Mar 2022');
    assert.match(cv.error, /end month/);
});

test('editing grows with content while review uses download page fitting', () => {
    const cv = builder();
    cv.$root = { classList: { contains: () => false } };
    const properties = {};
    const paper = { dataset: {template:'double-column'}, scrollHeight:1900, offsetHeight:1900 };
    const stage = {
        querySelector: selector => selector === '.cv-paper' ? paper : null,
        getBoundingClientRect: () => ({width:794}),
        classList: {contains: name => name === 'editor-paper-wrap'},
        style: {setProperty: (key,value) => {properties[key]=value;}, getPropertyValue: key => properties[key]},
    };
    cv.fitStage(stage);
    assert.equal(stage.style.height, '1900px');
    assert.equal(properties['--cv-fit-scale'], 1);
    cv.reviewing = true;
    stage.getBoundingClientRect = () => ({width:397});
    cv.fitStage(stage);
    assert.equal(stage.style.height, `${cv.pageDimensions().designHeight * .5}px`);
    assert.ok(properties['--cv-fit-scale'] < 1);
    assert.equal(properties['--cv-display-scale'], .5);
    cv.reviewing = false;
    cv.fitStage(stage);
    assert.equal(stage.style.height, '950px');
    assert.equal(properties['--cv-display-scale'], .5);
});


test('gallery keeps complete template examples when a draft is partially filled', () => {
    const cv = builder();
    cv.data.name = 'My real name';
    cv.data.summary = 'My real summary';
    const before = JSON.stringify(cv.data);
    const template = {id:'elegant',layout:'sidebar',font:'sans',accent:'#233e56',category:'Modern'};
    const gallery = cv.preview(template, true);
    assert.match(gallery, /John Doe/);
    assert.match(gallery, /Northstar Group/);
    assert.match(gallery, /University of Johannesburg/);
    assert.match(gallery, /template-elegant layout-sidebar/);
    assert.doesNotMatch(gallery, /My real name/);
    assert.match(cv.preview(template), /My real name/);
    assert.equal(JSON.stringify(cv.data), before);
});

test('builder Back returns to Dashboard from every step without changing the draft', async () => {
    const cv = builder();
    const destinations = [];
    cv.leave = async url => destinations.push(url);
    for (const step of [1, 2, 3, 4, 5]) {
        cv.step = step;
        cv.reviewing = step === 4;
        await cv.builderBack();
        assert.equal(destinations.pop(), '/dashboard');
        assert.equal(cv.step, step);
    }
    cv.busy = true;
    await cv.builderBack();
    assert.equal(destinations.length, 0);
});

test('the position question opens departments and selects its matching role', async () => {
    const cv = builder();
    cv.templates = [{id:'department-it'}];
    cv.departments = [{id:'it',title:'Information technology',template:'department-it',roles:['Software Developer']}];
    cv.data.role = 'Software Developer';
    let step;
    cv.go = async next => {step=next;};
    await cv.goToPositionTemplates();
    assert.equal(step, 3);
    assert.equal(cv.category, 'By Department');
    assert.equal(cv.departmentRoles.it, 'Software Developer');
});


test('failed navigation saves offer explicit discard and preserve changes on cancel', async () => {
    let destination;
    let discard = false;
    const cv = builder(async () => ({ ok: false, status: 422, json: async () => ({ errors: { 'data.email': ['The email field must be a valid email address.'] } }) }), {
        confirm: () => discard,
        location: { assign: url => { destination = url; } },
    });
    cv.data.email = 'invalid';
    await cv.leave('/dashboard');
    assert.equal(destination, undefined);
    assert.equal(cv.busy, false);
    assert.equal(cv.data.email, 'invalid');
    assert.equal(cv.discarding, false);
    discard = true;
    await cv.leave('/dashboard');
    assert.equal(destination, '/dashboard');
    assert.equal(cv.discarding, true);
});

test('every configured non-department category is exposed without duplicate templates', () => {
    const cv = builder();
    cv.templates = [{ id: 'position', category: 'By Position' }, { id: 'simple', category: 'Simple' }];
    cv.category = 'All';
    assert.deepEqual(Array.from(cv.templateGroups.flatMap(group => group.templates.map(t => t.id))).sort(), ['position', 'simple']);
});


test('an overlong inline edit is retained for correction instead of saving an older value', () => {
    const cv = builder();
    cv.data.name = 'Previous name';
    const value = 'N'.repeat(151);
    const field = { dataset: { field: 'name' }, textContent: value };
    cv.commitInline({ target: { closest: () => field } });
    assert.equal(cv.data.name, value);
    assert.match(cv.error, /too long/);
});

test('finish waits until edits made during an in-flight save have also been persisted', async () => {
    const requests = [];
    const destinations = [];
    const cv = builder((_url, options) => new Promise(resolve => requests.push({ payload: JSON.parse(options.body), resolve })), {
        location: { assign: url => destinations.push(url) },
    });
    cv.data.name = 'Before slow save';
    const finishing = cv.finish();
    await new Promise(setImmediate);
    cv.data.name = 'Latest name';
    requests[0].resolve({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await new Promise(setImmediate);
    assert.equal(destinations.length, 0, 'must not navigate after saving only stale data');
    assert.equal(requests.length, 2);
    assert.equal(requests[1].payload.data.name, 'Latest name');
    requests[1].resolve({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await finishing;
    assert.deepEqual(destinations, ['/finish']);
});


test('company and school names accept the same 200-character limit as the server', () => {
    const cv = builder();
    for (const [path, row, key] of [['experience.0.company', cv.data.experience[0], 'company'], ['education.0.school', cv.data.education[0], 'school']]) {
        const value = 'A'.repeat(175);
        cv.commitInline({ target: { closest: () => ({ dataset: { field: path }, textContent: value }) } });
        assert.equal(row[key], value);
        assert.equal(cv.error, '');
    }
});

test('clearing the name during a slow save keeps the editor open with a required-name error', async () => {
    const requests = [];
    let navigated = false;
    const cv = builder(() => new Promise(resolve => requests.push(resolve)), { location: { assign: () => { navigated = true; } } });
    cv.data.name = 'Original name';
    const finishing = cv.finish();
    await new Promise(setImmediate);
    cv.data.name = '';
    requests[0]({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await new Promise(setImmediate);
    requests[1]({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await finishing;
    assert.equal(navigated, false);
    assert.equal(cv.busy, false);
    assert.match(cv.error, /enter your name/);
});


test('a stale successful save cannot finish when the latest edit fails server validation', async () => {
    const requests = [];
    const destinations = [];
    const cv = builder(() => new Promise(resolve => requests.push(resolve)), { location: { assign: url => destinations.push(url) } });
    cv.data.name = 'Valid original';
    const finishing = cv.finish();
    await new Promise(setImmediate);
    cv.data.name = 'N'.repeat(151);
    requests[0]({ ok: true, status: 200, json: async () => ({ saved: true }) });
    await new Promise(setImmediate);
    requests[1]({ ok: false, status: 422, json: async () => ({ errors: { 'data.name': ['The name must not be greater than 150 characters.'] } }) });
    await finishing;
    assert.deepEqual(destinations, []);
    assert.equal(cv.busy, false);
    assert.equal(cv.data.name.length, 151);
    assert.match(cv.error, /150 characters/);
});
