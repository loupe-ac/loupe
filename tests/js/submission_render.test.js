/** @vitest-environment jsdom */
import { beforeAll, beforeEach, expect, it } from 'vitest';

let rendersSubmission;

beforeAll(async () => {
    ({ rendersSubmission } =
        await import('../../assets/lib/submission_render.js'));
});

function setVisit(visit) {
    window.Turbo = { session: { navigator: { currentVisit: visit } } };
}

function visit(href, response = {}) {
    return { location: new URL(href), response };
}

function fire(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail }));
}

function answer(href, type = 'text/html') {
    fire('turbo:submit-end', {
        fetchResponse: { header: () => type, location: new URL(href) },
    });
}

beforeEach(() => {
    setVisit(null);
    fire('turbo:submit-start');
});

it('treats a render with no visit as the answer of the form', () => {
    expect(rendersSubmission()).toBe(true);
});

it('recognises the response-backed visit that the form answer starts', () => {
    answer('http://localhost/settings');
    const own = visit('http://localhost/settings');
    setVisit(own);
    fire('turbo:visit');
    expect(rendersSubmission()).toBe(true);
});

it('ignores an unrelated response-backed visit between submit-end and the answer', () => {
    answer('http://localhost/settings');
    const other = visit('http://localhost/frame-fallback');
    setVisit(other);
    fire('turbo:visit');
    expect(rendersSubmission()).toBe(false);

    const own = visit('http://localhost/settings');
    setVisit(own);
    fire('turbo:visit');
    expect(rendersSubmission()).toBe(true);
});

it('ignores a visit with no response', () => {
    answer('http://localhost/settings');
    setVisit({ location: new URL('http://localhost/settings') });
    fire('turbo:visit');
    expect(rendersSubmission()).toBe(false);
});

it('ignores a visit after a Turbo Stream answer', () => {
    answer('http://localhost/settings', 'text/vnd.turbo-stream.html');
    setVisit(visit('http://localhost/settings'));
    fire('turbo:visit');
    expect(rendersSubmission()).toBe(false);
});
