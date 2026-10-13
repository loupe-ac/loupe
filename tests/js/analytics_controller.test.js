/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AnalyticsController from '../../assets/controllers/analytics_controller.js';

let application;
let umami;

async function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('analytics', AnalyticsController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
    umami = { track: vi.fn() };
    vi.stubGlobal('umami', umami);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
});

const BUTTON = (
    name,
) => `<a href="#waitlist" data-action="click->analytics#track"
    data-analytics-name-param="waitlist-click" data-analytics-button-param="${name}">Join</a>`;

it('sends the click event named by the button', async () => {
    await mount(`<div data-controller="analytics">${BUTTON('hero')}</div>`);
    document.querySelector('a').click();
    expect(umami.track).toHaveBeenCalledTimes(1);
    expect(umami.track).toHaveBeenCalledWith('waitlist-click', {
        button: 'hero',
    });
});

it('sends one event for each button, with its own label', async () => {
    await mount(
        `<div data-controller="analytics">${BUTTON('nav')}${BUTTON('footer')}</div>`,
    );
    const [first, second] = document.querySelectorAll('a');
    first.click();
    second.click();
    expect(umami.track.mock.calls).toEqual([
        ['waitlist-click', { button: 'nav' }],
        ['waitlist-click', { button: 'footer' }],
    ]);
});

it('does not cancel the click, so the dialog action still runs', async () => {
    await mount(`<div data-controller="analytics">${BUTTON('hero')}</div>`);
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector('a').dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
});

it('does nothing when the tracker is not loaded', async () => {
    vi.unstubAllGlobals();
    await mount(`<div data-controller="analytics">${BUTTON('hero')}</div>`);
    expect(() => document.querySelector('a').click()).not.toThrow();
});
