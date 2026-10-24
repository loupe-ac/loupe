/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import OverflowCueController from '../../assets/controllers/overflow_cue_controller.js';

let application;
let resizeCallback;
let disconnectObserver;
let reducedMotion;
let scrollIntoView;

const markup = (mode = 'scroll', withActiveTarget = false) => `
    <div data-controller="overflow-cue" data-overflow-cue-mode-value="${mode}">
        <button type="button" data-overflow-cue-target="prev"
            data-action="overflow-cue#scrollPrev">prev</button>
        <nav data-overflow-cue-target="viewport">
            <a id="first" href="#">First</a>
            <a id="current" href="#" aria-current="page"
                ${withActiveTarget ? 'data-overflow-cue-target="active"' : ''}>Current</a>
            <a id="pressed" href="#" hidden aria-pressed="true">Pressed</a>
        </nav>
        <button type="button" data-overflow-cue-target="next"
            data-action="overflow-cue#scrollNext">next</button>
    </div>`;

function geometry(viewport, { scrollWidth, clientWidth, scrollLeft }) {
    Object.defineProperty(viewport, 'scrollWidth', {
        configurable: true,
        value: scrollWidth,
    });
    Object.defineProperty(viewport, 'clientWidth', {
        configurable: true,
        value: clientWidth,
    });
    viewport.scrollLeft = scrollLeft;
}

async function start(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('overflow-cue', OverflowCueController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const root = () => document.querySelector('[data-controller]');
const viewport = () => document.querySelector('nav');

beforeEach(() => {
    reducedMotion = false;
    disconnectObserver = vi.fn();
    scrollIntoView = vi.fn();
    Element.prototype.scrollIntoView = scrollIntoView;
    vi.stubGlobal(
        'ResizeObserver',
        class {
            constructor(callback) {
                resizeCallback = callback;
            }
            observe() {}
            disconnect() {
                disconnectObserver();
            }
        },
    );
    vi.stubGlobal('matchMedia', () => ({ matches: reducedMotion }));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
});

it('marks only the end edge at the start of the scroll', async () => {
    await start(markup());
    geometry(viewport(), { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
    resizeCallback();
    expect(root().hasAttribute('data-overflow-start')).toBe(false);
    expect(root().hasAttribute('data-overflow-end')).toBe(true);
});

it('marks both edges in the middle', async () => {
    await start(markup());
    geometry(viewport(), {
        scrollWidth: 600,
        clientWidth: 300,
        scrollLeft: 100,
    });
    viewport().dispatchEvent(new Event('scroll'));
    expect(root().hasAttribute('data-overflow-start')).toBe(true);
    expect(root().hasAttribute('data-overflow-end')).toBe(true);
});

it('marks only the start edge at the end of the scroll', async () => {
    await start(markup());
    geometry(viewport(), {
        scrollWidth: 600,
        clientWidth: 300,
        scrollLeft: 300,
    });
    viewport().dispatchEvent(new Event('scroll'));
    expect(root().hasAttribute('data-overflow-start')).toBe(true);
    expect(root().hasAttribute('data-overflow-end')).toBe(false);
});

it('marks no edge when the content fits', async () => {
    await start(markup());
    geometry(viewport(), { scrollWidth: 300, clientWidth: 300, scrollLeft: 0 });
    resizeCallback();
    expect(root().hasAttribute('data-overflow-start')).toBe(false);
    expect(root().hasAttribute('data-overflow-end')).toBe(false);
});

it('dispatches the scrolled event on scroll', async () => {
    await start(markup());
    const scrolled = vi.fn();
    root().addEventListener('overflow-cue:scrolled', scrolled);
    viewport().dispatchEvent(new Event('scroll'));
    expect(scrolled).toHaveBeenCalledTimes(1);
});

function place(id, left, right) {
    document.getElementById(id).getBoundingClientRect = () => ({ left, right });
}

function stripBox(left, right) {
    viewport().getBoundingClientRect = () => ({ left, right });
}

it('scrolls a hidden active target into view on connect', async () => {
    document.body.innerHTML = markup('scroll', true);
    stripBox(0, 300);
    place('current', 340, 420);
    application = Application.start();
    application.register('overflow-cue', OverflowCueController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(viewport().scrollLeft).toBe(164);
    expect(scrollIntoView).not.toHaveBeenCalled();
});

it('scrolls back to an active target hidden at the start', async () => {
    document.body.innerHTML = markup();
    stripBox(0, 300);
    place('current', -80, -10);
    viewport().scrollLeft = 200;
    application = Application.start();
    application.register('overflow-cue', OverflowCueController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(viewport().scrollLeft).toBe(76);
});

it('leaves a visible active target where it is', async () => {
    document.body.innerHTML = markup();
    stripBox(0, 300);
    place('current', 60, 120);
    application = Application.start();
    application.register('overflow-cue', OverflowCueController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(viewport().scrollLeft).toBe(0);
});

it('re-resolves the active tab on reveal', async () => {
    await start(markup());
    document.getElementById('current').removeAttribute('aria-current');
    document.getElementById('first').setAttribute('aria-selected', 'true');
    stripBox(0, 300);
    place('first', 310, 380);
    viewport().scrollLeft = 0;
    const controller = application.getControllerForElementAndIdentifier(
        root(),
        'overflow-cue',
    );
    controller.reveal();
    expect(viewport().scrollLeft).toBe(124);
});

it('reveals the new current tab when the markup switches it', async () => {
    await start(markup());
    stripBox(0, 300);
    place('current', 60, 120);
    place('first', 310, 380);
    viewport().scrollLeft = 0;
    document.getElementById('current').removeAttribute('aria-current');
    document.getElementById('first').setAttribute('aria-selected', 'true');
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(viewport().scrollLeft).toBe(124);
});

it('moves focus off an arrow that the end of the strip hides', async () => {
    await start(markup());
    geometry(viewport(), { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
    resizeCallback();
    const next = document.querySelector('[data-overflow-cue-target="next"]');
    next.focus();
    geometry(viewport(), {
        scrollWidth: 600,
        clientWidth: 300,
        scrollLeft: 300,
    });
    viewport().dispatchEvent(new Event('scroll'));
    expect(document.activeElement).toBe(document.getElementById('current'));
});

it('scrolls by 90 percent of the width in scroll mode', async () => {
    await start(markup());
    geometry(viewport(), { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
    viewport().scrollBy = vi.fn();
    document.querySelector('[data-overflow-cue-target="next"]').click();
    expect(viewport().scrollBy).toHaveBeenLastCalledWith({
        left: 270,
        behavior: 'smooth',
    });
    document.querySelector('[data-overflow-cue-target="prev"]').click();
    expect(viewport().scrollBy).toHaveBeenLastCalledWith({
        left: -270,
        behavior: 'smooth',
    });
});

it('scrolls without animation under reduced motion', async () => {
    reducedMotion = true;
    await start(markup());
    geometry(viewport(), { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
    viewport().scrollBy = vi.fn();
    document.querySelector('[data-overflow-cue-target="next"]').click();
    expect(viewport().scrollBy).toHaveBeenCalledWith({
        left: 270,
        behavior: 'auto',
    });
});

it('dispatches the step event and scrolls nothing in step mode', async () => {
    await start(markup('step'));
    viewport().scrollBy = vi.fn();
    const stepped = vi.fn();
    root().addEventListener('overflow-cue:step', (event) =>
        stepped(event.detail),
    );
    document.querySelector('[data-overflow-cue-target="next"]').click();
    document.querySelector('[data-overflow-cue-target="prev"]').click();
    expect(stepped.mock.calls).toEqual([
        [{ direction: 1 }],
        [{ direction: -1 }],
    ]);
    expect(viewport().scrollBy).not.toHaveBeenCalled();
});

it('stops observing and listening on disconnect', async () => {
    await start(markup());
    const element = viewport();
    const host = root();
    const scrolled = vi.fn();
    host.addEventListener('overflow-cue:scrolled', scrolled);
    host.remove();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(disconnectObserver).toHaveBeenCalledTimes(1);
    element.dispatchEvent(new Event('scroll'));
    expect(scrolled).not.toHaveBeenCalled();
});

it('marks the edges of a strip with no arrows', async () => {
    await start(`
        <div data-controller="overflow-cue">
            <nav data-overflow-cue-target="viewport">
                <a href="#">First</a>
                <a href="#" aria-current="page">Current</a>
            </nav>
        </div>`);
    geometry(viewport(), { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
    expect(() => resizeCallback()).not.toThrow();
    expect(root().hasAttribute('data-overflow-end')).toBe(true);
});
