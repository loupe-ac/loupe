/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import LandingAskController from '../../assets/controllers/landing_ask_controller.js';

let application;

async function flush() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(async () => {
    application = Application.start();
    application.register('landing-ask', LandingAskController);
    document.body.innerHTML = `<div data-controller="landing-ask">
        <button type="button" aria-expanded="false" data-landing-ask-target="toggle"
                data-action="landing-ask#toggle">Use something else?</button>
        <div data-landing-ask-target="body" hidden></div>
    </div>`;
    await flush();
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

const toggle = () =>
    document.querySelector('[data-landing-ask-target="toggle"]');
const body = () => document.querySelector('[data-landing-ask-target="body"]');

it('starts closed', () => {
    expect(body().hidden).toBe(true);
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
});

it('opens on a click', async () => {
    toggle().click();
    await flush();

    expect(body().hidden).toBe(false);
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
});

it('closes on a second click', async () => {
    toggle().click();
    await flush();
    toggle().click();
    await flush();

    expect(body().hidden).toBe(true);
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
});
