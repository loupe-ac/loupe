/** @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

globalThis.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

const { morphTurboFrameElements } = await import('@hotwired/turbo');
const { FADE_MILLISECONDS } = await import('../../assets/lib/live_fade.js');

const row = (id) =>
    `<div id="row-${id}" data-live-row="${id}" data-live-fade data-card-id="${id}"><span id="menu-${id}">${id}</span></div>`;
const list = (ids) => `<div id="list">${ids.map(row).join('')}</div>`;

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    document.body.innerHTML = `<div id="frame">${list(['c', 'b', 'a'])}</div>`;
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
});

it('removes a leaving row from the page once the fade ends, after a real morph', () => {
    const frame = document.getElementById('frame');
    const fresh = document.createElement('div');
    fresh.id = 'frame';
    fresh.innerHTML = list(['c', 'a']);

    frame.addEventListener('turbo:before-frame-morph', () => {});
    morphTurboFrameElements(frame, fresh);
    vi.advanceTimersByTime(FADE_MILLISECONDS + 50);

    expect(
        [...document.querySelectorAll('[data-card-id]')].map(
            (element) => element.dataset.cardId,
        ),
    ).toEqual(['c', 'a']);
});
