/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import LandingExplainerController from '../../assets/controllers/landing_explainer_controller.js';

let application;

const interval = 1000;
const count = 3;

function markup() {
    const scenes = Array.from(
        { length: count },
        () => '<li data-landing-explainer-target="scene"></li>',
    ).join('');
    const dots = Array.from(
        { length: count },
        (_, index) => `<button type="button" data-landing-explainer-target="dot"
            data-action="landing-explainer#pick"
            data-landing-explainer-index-param="${index}"></button>`,
    ).join('');

    return `<div data-controller="landing-explainer"
            data-landing-explainer-interval-value="${interval}"
            data-landing-explainer-play-label-value="Play"
            data-landing-explainer-pause-label-value="Pause"
            data-action="mouseenter->landing-explainer#hold mouseleave->landing-explainer#release focusin->landing-explainer#focusIn focusout->landing-explainer#focusOut">
        <ol>${scenes}</ol>
        ${dots}
        <button type="button" id="previous" data-action="landing-explainer#previous"></button>
        <button type="button" id="next" data-action="landing-explainer#next"></button>
        <button type="button" id="toggle" data-landing-explainer-target="toggle"
                data-action="landing-explainer#toggle"><span data-landing-explainer-target="toggleLabel"></span></button>
    </div>`;
}

function stubMotion(reduced) {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: reduced,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    });
}

async function mount() {
    document.body.innerHTML = markup();
    await vi.advanceTimersByTimeAsync(0);
}

function shown() {
    return [
        ...document.querySelectorAll('[data-landing-explainer-target="scene"]'),
    ].findIndex((scene) => scene.getAttribute('aria-hidden') === 'false');
}

async function press(element) {
    element.click();
    await vi.advanceTimersByTimeAsync(0);
}

async function tick(milliseconds) {
    await vi.advanceTimersByTimeAsync(milliseconds);
}

beforeEach(() => {
    vi.useFakeTimers();
    stubMotion(false);
    application = Application.start();
    application.register('landing-explainer', LandingExplainerController);
});

afterEach(() => {
    application.stop();
    document.body.innerHTML = '';
    vi.useRealTimers();
});

it('shows the first scene and marks its dot as current', async () => {
    await mount();

    expect(shown()).toBe(0);
    const dots = document.querySelectorAll(
        '[data-landing-explainer-target="dot"]',
    );
    expect(dots[0].getAttribute('aria-current')).toBe('step');
    expect(dots[1].hasAttribute('aria-current')).toBe(false);
});

it('advances on the timer and loops back to the first scene', async () => {
    await mount();

    await tick(interval);
    expect(shown()).toBe(1);
    await tick(interval);
    expect(shown()).toBe(2);
    await tick(interval);
    expect(shown()).toBe(0);
});

it('makes every hidden scene inert', async () => {
    await mount();

    const scenes = document.querySelectorAll(
        '[data-landing-explainer-target="scene"]',
    );
    expect([...scenes].map((scene) => scene.inert)).toEqual([
        false,
        true,
        true,
    ]);
});

it('steps by hand with previous and next, and wraps around', async () => {
    await mount();

    await press(document.getElementById('previous'));
    expect(shown()).toBe(2);
    await press(document.getElementById('next'));
    expect(shown()).toBe(0);
});

it('jumps to a scene when its dot is clicked', async () => {
    await mount();

    await press(
        document.querySelectorAll('[data-landing-explainer-target="dot"]')[2],
    );

    expect(shown()).toBe(2);
});

it('restarts the timer after a manual step', async () => {
    await mount();

    await tick(interval - 100);
    await press(document.getElementById('next'));
    await tick(interval - 100);
    expect(shown()).toBe(1);
    await tick(100);
    expect(shown()).toBe(2);
});

it('pauses while the pointer is over the frame and resumes after', async () => {
    await mount();
    const root = document.querySelector('[data-controller]');

    root.dispatchEvent(new Event('mouseenter'));
    await tick(interval * 5);
    expect(shown()).toBe(0);

    root.dispatchEvent(new Event('mouseleave'));
    await tick(interval);
    expect(shown()).toBe(1);
});

it('pauses while a control has keyboard focus', async () => {
    await mount();
    const next = document.getElementById('next');

    next.matches = () => true;
    next.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    await tick(interval * 5);
    expect(shown()).toBe(0);

    next.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
    await tick(interval);
    expect(shown()).toBe(1);
});

it('keeps running after a mouse click puts focus on a control', async () => {
    await mount();
    const next = document.getElementById('next');

    next.matches = () => false;
    next.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    await tick(interval);

    expect(shown()).toBe(1);
});

it('stops and starts with the play and pause button', async () => {
    await mount();
    const toggle = document.getElementById('toggle');
    expect(toggle.getAttribute('aria-label')).toBe('Pause');

    await press(toggle);
    expect(toggle.getAttribute('aria-label')).toBe('Play');
    expect(toggle.dataset.playing).toBe('false');
    await tick(interval * 5);
    expect(shown()).toBe(0);

    await press(toggle);
    expect(toggle.getAttribute('aria-label')).toBe('Pause');
    await tick(interval);
    expect(shown()).toBe(1);
});

it('never advances by itself under reduced motion, and hides the toggle', async () => {
    stubMotion(true);
    await mount();

    await tick(interval * 5);
    expect(shown()).toBe(0);
    expect(document.getElementById('toggle').hidden).toBe(true);

    await press(document.getElementById('next'));
    expect(shown()).toBe(1);
    await tick(interval * 5);
    expect(shown()).toBe(1);
});

it('stops its timer when it disconnects', async () => {
    await mount();

    document.body.innerHTML = '';
    await tick(interval * 3);

    expect(vi.getTimerCount()).toBe(0);
});
