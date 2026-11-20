/** @vitest-environment jsdom */
import { beforeAll, expect, it } from 'vitest';

beforeAll(async () => {
    await import('../../assets/lib/morph_typed_forms.js');
});

function morph(current) {
    const event = new CustomEvent('turbo:before-morph-element', {
        bubbles: true,
        cancelable: true,
        detail: { currentElement: current, newElement: null },
    });
    current.dispatchEvent(event);

    return event.defaultPrevented;
}

it('keeps a form with typed text out of a morph', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    expect(morph(form)).toBe(true);
});

it('lets the submitting form take its answer once its request ended', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { fetchResponse: { header: () => 'text/html' } },
        }),
    );
    expect(morph(form)).toBe(false);
});

it('keeps the form again once a render ended the submission', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { fetchResponse: { header: () => 'text/html' } },
        }),
    );
    document.documentElement.dispatchEvent(
        new CustomEvent('turbo:morph', { bubbles: true }),
    );
    expect(morph(form)).toBe(true);
});

it('keeps the submitting form through a live morph while its request runs, when Turbo holds no visit', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    window.Turbo = { session: { navigator: { currentVisit: null } } };
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    expect(morph(form)).toBe(true);
    document.documentElement.dispatchEvent(
        new CustomEvent('turbo:morph', { bubbles: true }),
    );
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { fetchResponse: { header: () => 'text/html' } },
        }),
    );
    expect(morph(form)).toBe(false);
    delete window.Turbo;
});

it('lets an untouched form morph', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    expect(morph(document.getElementById('f'))).toBe(false);
});

function submitEnd(form, fetchResponse) {
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { fetchResponse },
        }),
    );
}

it('keeps the form again after a Turbo Stream answer', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    submitEnd(form, {
        header: () => 'text/vnd.turbo-stream.html; charset=utf-8',
    });
    expect(morph(form)).toBe(true);
});

it('keeps the form again after a failed request', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    submitEnd(form, undefined);
    expect(morph(form)).toBe(true);
});

it('lets the form take an HTML answer that ends the request', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    submitEnd(form, { header: () => 'text/html; charset=utf-8' });
    expect(morph(form)).toBe(false);
});

const SUBMISSION = { id: 'form' };

function startVisit(visit) {
    window.Turbo = { session: { navigator: {} } };
    document.dispatchEvent(
        new CustomEvent('turbo:before-visit', {
            detail: { url: visit.location.href },
        }),
    );
    window.Turbo = { session: { navigator: { currentVisit: visit } } };
    document.dispatchEvent(new CustomEvent('turbo:visit'));
}

it('keeps the submitting form through an unrelated morph after the request ended', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: {
                fetchResponse: {
                    header: () => 'text/html',
                    location: new URL('http://localhost/settings'),
                },
                formSubmission: SUBMISSION,
            },
        }),
    );
    startVisit({
        response: undefined,
        location: new URL('http://localhost/settings'),
    });
    expect(morph(form)).toBe(true);
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(morph(form)).toBe(true);
    startVisit({
        response: { responseHTML: '<html></html>' },
        location: new URL('http://localhost/frame-fallback'),
    });
    expect(morph(form)).toBe(true);
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(morph(form)).toBe(true);
    startVisit({
        response: { responseHTML: '<html></html>' },
        location: new URL('http://localhost/settings'),
    });
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(morph(form)).toBe(true);
    delete window.Turbo;
});

it('keeps the submitting form through a live morph while its request runs', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    window.Turbo = {
        session: {
            navigator: {
                currentVisit: {
                    response: { responseHTML: '<html></html>' },
                    location: new URL('http://localhost/settings'),
                },
            },
        },
    };
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    expect(morph(form)).toBe(true);
    delete window.Turbo;
});
