/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import ReferenceLinkController from '../../assets/controllers/reference_link_controller.js';

let application;

const rectangle = (left, top, width, height) => ({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
});

beforeEach(async () => {
    document.body.innerHTML = `
        <div data-controller="reference-link">
            <p><a id="card" href="#" data-ref-tooltip-title="Gate new work" data-ref-tooltip-line="Implementation · Working">card #1</a>
            <a id="state" href="#" data-ref-tooltip-line="Open">pr</a>
            <a id="plain" href="#">plain</a></p>
            <span id="tip" popover="manual" data-reference-link-target="tooltip">
                <span id="title" data-reference-link-target="title" hidden></span>
                <span id="line" data-reference-link-target="line" hidden></span>
            </span>
        </div>`;
    document.querySelector('#card').getBoundingClientRect = () =>
        rectangle(100, 50, 60, 16);
    document.querySelector('#tip').getBoundingClientRect = () =>
        rectangle(0, 0, 288, 60);
    application = Application.start();
    application.register('reference-link', ReferenceLinkController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    document.body.replaceChildren();
    application.stop();
});

const fire = (selector, type, init = {}) =>
    document
        .querySelector(selector)
        .dispatchEvent(new Event(type, { bubbles: true, ...init }));
const tip = () => document.querySelector('#tip');

describe('reference link tooltip', () => {
    it('fills both lines with text and opens under the link on hover', () => {
        fire('#card', 'pointerover');

        expect(tip().hasAttribute('data-open')).toBe(true);
        expect(document.querySelector('#title').textContent).toBe(
            'Gate new work',
        );
        expect(document.querySelector('#line').textContent).toBe(
            'Implementation · Working',
        );
        expect(tip().style.left).toBe('100px');
        expect(tip().style.top).toBe('74px');
        expect(
            document.querySelector('#card').getAttribute('aria-describedby'),
        ).toBe(tip().id);
    });

    it('hides the title line when the link has none', () => {
        fire('#state', 'pointerover');

        expect(document.querySelector('#title').hidden).toBe(true);
        expect(document.querySelector('#line').hidden).toBe(false);
    });

    it('writes the title as text, never as HTML', () => {
        document.querySelector('#card').dataset.refTooltipTitle = '<b>x</b>';
        fire('#card', 'pointerover');

        expect(document.querySelector('#title').querySelector('b')).toBeNull();
    });

    it('ignores a link with no tooltip data', () => {
        fire('#plain', 'pointerover');

        expect(tip().hasAttribute('data-open')).toBe(false);
    });

    it('closes when the pointer leaves, on Escape and on a click', () => {
        fire('#card', 'pointerover');
        fire('#card', 'pointerout');
        expect(tip().hasAttribute('data-open')).toBe(false);

        fire('#card', 'pointerover');
        const escape = new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
        });
        document.querySelector('#card').dispatchEvent(escape);
        expect(tip().hasAttribute('data-open')).toBe(false);

        fire('#card', 'pointerover');
        fire('#card', 'click');
        expect(tip().hasAttribute('data-open')).toBe(false);
        expect(
            document.querySelector('#card').hasAttribute('aria-describedby'),
        ).toBe(false);
    });

    it('stays open while hover or focus remains on the link', () => {
        const card = document.querySelector('#card');
        card.matches = (selector) =>
            selector === ':focus-visible' ||
            Element.prototype.matches.call(card, selector);
        card.focus();
        fire('#card', 'pointerover');
        fire('#card', 'pointerout');
        expect(tip().hasAttribute('data-open')).toBe(true);

        fire('#card', 'pointerover');
        card.blur();
        expect(tip().hasAttribute('data-open')).toBe(true);

        fire('#card', 'pointerout');
        expect(tip().hasAttribute('data-open')).toBe(false);
    });

    it('does not open on a touch pointer', () => {
        const event = new Event('pointerover', { bubbles: true });
        event.pointerType = 'touch';
        document.querySelector('#card').dispatchEvent(event);

        expect(tip().hasAttribute('data-open')).toBe(false);
    });
});
