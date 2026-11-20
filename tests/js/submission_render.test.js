/** @vitest-environment jsdom */
import { beforeAll, beforeEach, expect, it } from 'vitest';

let rendersSubmission;

beforeAll(async () => {
    ({ rendersSubmission } =
        await import('../../assets/lib/submission_render.js'));
});

const SUBMISSION = { id: 'form' };
const ANSWER_HTML = '<html>answer</html>';

function setVisit(visit, formSubmission = undefined) {
    window.Turbo = {
        session: { navigator: { currentVisit: visit, formSubmission } },
    };
}

function visit(href, response = { responseHTML: ANSWER_HTML }) {
    return { location: new URL(href), response };
}

function fire(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail }));
}

function answer(href, type = 'text/html') {
    fire('turbo:submit-end', {
        fetchResponse: {
            header: () => type,
            location: new URL(href),
            responseHTML: Promise.resolve(ANSWER_HTML),
        },
        formSubmission: SUBMISSION,
    });
}

function propose(next, formSubmission) {
    setVisit(null, formSubmission);
    fire('turbo:before-visit', { url: next.location.href });
    setVisit(next);
    fire('turbo:visit');
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
    propose(visit('http://localhost/settings'), SUBMISSION);
    expect(rendersSubmission()).toBe(true);
});

it('ignores a same-URL visit that the form did not propose', () => {
    answer('http://localhost/settings');
    propose(visit('http://localhost/settings'), null);
    expect(rendersSubmission()).toBe(false);
});

it('ignores a visit to another URL while the submission is still held', () => {
    answer('http://localhost/settings');
    propose(visit('http://localhost/frame-fallback'), SUBMISSION);
    expect(rendersSubmission()).toBe(false);
});

it('ignores a same-URL visit that another submission or visit replaced', () => {
    answer('http://localhost/settings');
    propose(visit('http://localhost/settings'), { id: 'other' });
    expect(rendersSubmission()).toBe(false);
});

it('ignores a visit with no response', () => {
    answer('http://localhost/settings');
    propose({ location: new URL('http://localhost/settings') }, SUBMISSION);
    expect(rendersSubmission()).toBe(false);
});

it('ignores a visit after a Turbo Stream answer', () => {
    answer('http://localhost/settings', 'text/vnd.turbo-stream.html');
    propose(visit('http://localhost/settings'), SUBMISSION);
    expect(rendersSubmission()).toBe(false);
});

it('ignores a same-URL fallback with another body while the submission is held', async () => {
    answer('http://localhost/settings');
    await Promise.resolve();
    propose(
        visit('http://localhost/settings', {
            responseHTML: '<html>frame</html>',
        }),
        SUBMISSION,
    );
    expect(rendersSubmission()).toBe(false);
});

it('drops a visit whose body differs once the answer body arrives', async () => {
    answer('http://localhost/settings');
    propose(
        visit('http://localhost/settings', {
            responseHTML: '<html>frame</html>',
        }),
        SUBMISSION,
    );
    await Promise.resolve();
    await Promise.resolve();
    expect(rendersSubmission()).toBe(false);
});

it('keeps the visit that carries the body of the answer', async () => {
    answer('http://localhost/settings');
    propose(visit('http://localhost/settings'), SUBMISSION);
    await Promise.resolve();
    await Promise.resolve();
    expect(rendersSubmission()).toBe(true);
});

it('keeps the submission pending after a rejected fallback so the real answer matches', async () => {
    answer('http://localhost/settings');
    await new Promise((resolve) => setTimeout(resolve));
    propose(
        visit('http://localhost/settings', {
            responseHTML: '<html>frame</html>',
        }),
        SUBMISSION,
    );
    expect(rendersSubmission()).toBe(false);
    propose(visit('http://localhost/settings'), SUBMISSION);
    expect(rendersSubmission()).toBe(true);
});
