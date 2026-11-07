/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import DisclosureAutofocusController from '../../assets/controllers/disclosure_autofocus_controller.js';

let application;

beforeEach(async () => {
    document.body.innerHTML = `
        <details data-controller="disclosure-autofocus">
            <summary>Open</summary>
            <textarea data-disclosure-autofocus-target="field"></textarea>
        </details>`;
    application = Application.start();
    application.register('disclosure-autofocus', DisclosureAutofocusController);
    await Promise.resolve();
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

const toggle = (details, open) => {
    details.open = open;
    details.dispatchEvent(new Event('toggle'));
};

it('focuses the field when the section opens', () => {
    const details = document.querySelector('details');
    toggle(details, true);

    expect(document.activeElement).toBe(document.querySelector('textarea'));
});

it('leaves the focus alone when the section closes', () => {
    const details = document.querySelector('details');
    const summary = document.querySelector('summary');
    toggle(details, true);
    summary.tabIndex = 0;
    summary.focus();
    toggle(details, false);

    expect(document.activeElement).toBe(summary);
});

it('does nothing when the section has no field', () => {
    document.querySelector('textarea').remove();
    const details = document.querySelector('details');

    expect(() => toggle(details, true)).not.toThrow();
});
