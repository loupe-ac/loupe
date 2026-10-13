/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import LandingFeaturesController from '../../assets/controllers/landing_features_controller.js';

let application;

const names = ['Board', 'Workflow rules', 'Document review', 'Analytics'];

function markup() {
    const items = names
        .map(
            (name, index) => `
            <button type="button" data-landing-features-target="item"
                    data-action="landing-features#pick"
                    data-landing-features-index-param="${index}"
                    aria-pressed="${index === 0}">${name}</button>
            <span data-landing-features-target="description"${index === 0 ? '' : ' hidden'}>${name} text</span>`,
        )
        .join('');
    const slides = names
        .map(() => '<div data-landing-features-target="slide"></div>')
        .join('');

    return `<section data-controller="landing-features">
        ${items}
        <div data-landing-features-target="track">${slides}</div>
        <button type="button" data-action="landing-features#previous" id="previous"></button>
        <button type="button" data-action="landing-features#next" id="next"></button>
    </section>`;
}

async function mount() {
    document.body.innerHTML = markup();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function state() {
    const pressed = [
        ...document.querySelectorAll('[data-landing-features-target="item"]'),
    ].map((item) => item.getAttribute('aria-pressed') === 'true');
    const shown = [
        ...document.querySelectorAll(
            '[data-landing-features-target="description"]',
        ),
    ].map((description) => !description.hidden);
    const slides = [
        ...document.querySelectorAll('[data-landing-features-target="slide"]'),
    ].map((slide) => slide.getAttribute('aria-hidden') === 'false');

    return { pressed, shown, slides };
}

async function flush() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(async () => {
    application = Application.start();
    application.register('landing-features', LandingFeaturesController);
    await mount();
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('starts on the first feature', () => {
    const { pressed, shown, slides } = state();

    expect(pressed).toEqual([true, false, false, false]);
    expect(shown).toEqual([true, false, false, false]);
    expect(slides).toEqual([true, false, false, false]);
    expect(
        document.querySelector('[data-landing-features-target="track"]').style
            .transform,
    ).toBe('translateX(0%)');
});

it('marks the slides that are not shown as inert', () => {
    const slides = [
        ...document.querySelectorAll('[data-landing-features-target="slide"]'),
    ];

    expect(slides.map((slide) => slide.inert)).toEqual([
        false,
        true,
        true,
        true,
    ]);
});

it('shows the picked feature, its description and its slide', async () => {
    document
        .querySelectorAll('[data-landing-features-target="item"]')[2]
        .click();
    await flush();

    const { pressed, shown, slides } = state();
    expect(pressed).toEqual([false, false, true, false]);
    expect(shown).toEqual([false, false, true, false]);
    expect(slides).toEqual([false, false, true, false]);
    expect(
        document.querySelector('[data-landing-features-target="track"]').style
            .transform,
    ).toBe('translateX(-200%)');
});

it('wraps from the first feature back to the last', async () => {
    document.getElementById('previous').click();
    await flush();

    expect(state().pressed).toEqual([false, false, false, true]);
});

it('wraps from the last feature forward to the first', async () => {
    document
        .querySelectorAll('[data-landing-features-target="item"]')[3]
        .click();
    await flush();
    document.getElementById('next').click();
    await flush();

    expect(state().pressed).toEqual([true, false, false, false]);
});
