/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AnalyticsJoinController from '../../assets/controllers/analytics_join_controller.js';

let application;
let umami;

async function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('analytics-join', AnalyticsJoinController);
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

it.each(['dialog', 'notify', 'page'])(
    'sends the join event for the %s form',
    async (form) => {
        await mount(
            `<div data-controller="analytics-join" data-analytics-join-form-value="${form}">Saved</div>`,
        );
        expect(umami.track).toHaveBeenCalledTimes(1);
        expect(umami.track).toHaveBeenCalledWith('waitlist-join', { form });
    },
);

it('removes its own data-controller so a cached copy sends nothing', async () => {
    await mount(
        '<div data-controller="analytics-join" data-analytics-join-form-value="dialog">Saved</div>',
    );
    expect(document.querySelector('div').hasAttribute('data-controller')).toBe(
        false,
    );

    const snapshot = document.body.innerHTML;
    application.stop();
    umami.track.mockClear();
    await mount(snapshot);
    expect(umami.track).not.toHaveBeenCalled();
});

it('sends nothing before a confirmation exists', async () => {
    await mount('<div>Form</div>');
    expect(umami.track).not.toHaveBeenCalled();
});

it('does not fail when the tracker is not loaded', async () => {
    vi.unstubAllGlobals();
    await expect(
        mount(
            '<div data-controller="analytics-join" data-analytics-join-form-value="page">Saved</div>',
        ),
    ).resolves.toBeUndefined();
});
