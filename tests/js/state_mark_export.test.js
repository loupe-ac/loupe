/** @vitest-environment jsdom */
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const template = readFileSync(
    'templates/Module/DesignSystem/export/core/StateMark/component.jsx.twig',
    'utf8',
);
// The tooltip logic holds no JSX, so it runs as plain script.
const logic = template.slice(
    template.indexOf('const CLOSE_DELAY'),
    template.indexOf('export function'),
);
const attachTooltip = new Function(logic + '\nreturn attachTooltip;')();

const rectangle = (left, top, width, height) => ({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
});

let detach;

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = `
        <span id="root">
            <span id="mark" tabindex="0"></span>
            <span id="tooltip"><a id="link" href="#">Open card</a></span>
        </span>
        <button id="outside"></button>`;
    root().getBoundingClientRect = () => rectangle(400, 100, 16, 16);
    tooltip().getBoundingClientRect = () => rectangle(0, 0, 288, 80);
    detach = attachTooltip(root(), tooltip());
});

afterEach(() => {
    detach();
    vi.useRealTimers();
});

const root = () => document.querySelector('#root');
const tooltip = () => document.querySelector('#tooltip');
const isOpen = () => tooltip().hasAttribute('data-open');
const pointer = (type, pointerType = 'mouse') =>
    Object.assign(new Event(type, { bubbles: true }), { pointerType });

it('opens under the mark, level with its right edge, when the pointer enters it', () => {
    root().dispatchEvent(pointer('pointerenter'));

    expect(isOpen()).toBe(true);
    expect(tooltip().style.left).toBe('132px');
    expect(tooltip().style.top).toBe('124px');
});

it('stays open while the pointer crosses from the mark into the tooltip', async () => {
    root().dispatchEvent(pointer('pointerenter'));
    root().dispatchEvent(pointer('pointerleave'));
    await vi.advanceTimersByTimeAsync(100);
    root().dispatchEvent(pointer('pointerenter'));
    await vi.advanceTimersByTimeAsync(500);

    expect(isOpen()).toBe(true);
});

it('closes a moment after the pointer leaves', async () => {
    root().dispatchEvent(pointer('pointerenter'));
    root().dispatchEvent(pointer('pointerleave'));
    await vi.advanceTimersByTimeAsync(200);

    expect(isOpen()).toBe(false);
});

it('stays open after a touch leaves, and closes on a press outside', async () => {
    root().dispatchEvent(pointer('pointerenter', 'touch'));
    root().dispatchEvent(pointer('pointerleave', 'touch'));
    await vi.advanceTimersByTimeAsync(500);
    expect(isOpen()).toBe(true);

    document
        .querySelector('#outside')
        .dispatchEvent(pointer('pointerdown', 'touch'));
    expect(isOpen()).toBe(false);
});

it('opens on keyboard focus and closes on Escape with the focus back on the mark', () => {
    document.querySelector('#mark').focus();
    expect(isOpen()).toBe(true);

    document.querySelector('#link').focus();
    document
        .querySelector('#link')
        .dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );

    expect(isOpen()).toBe(false);
    expect(document.activeElement).toBe(document.querySelector('#mark'));
});

it('gives way to a mouse press on the mark, which starts a drag', () => {
    root().dispatchEvent(pointer('pointerenter'));
    document.querySelector('#mark').dispatchEvent(pointer('pointerdown'));

    expect(isOpen()).toBe(false);
});

it('stops listening once it is detached', () => {
    detach();
    root().dispatchEvent(pointer('pointerenter'));

    expect(isOpen()).toBe(false);
    detach = () => {};
});
