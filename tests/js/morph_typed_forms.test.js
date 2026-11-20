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

it('lets the submitting form take its answer', () => {
    document.body.innerHTML =
        '<form id="f" aria-busy="true"><textarea></textarea></form>';
    const form = document.getElementById('f');
    form.querySelector('textarea').value = 'typed';
    expect(morph(form)).toBe(false);
});

it('lets an untouched form morph', () => {
    document.body.innerHTML = '<form id="f"><textarea></textarea></form>';
    expect(morph(document.getElementById('f'))).toBe(false);
});
