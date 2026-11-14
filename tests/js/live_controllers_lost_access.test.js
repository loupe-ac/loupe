/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);

        return () => {
            subscription.removed = true;
        };
    },
    status: () => () => {},
}));

const { handleLostAccess, reset } =
    await import('../../assets/lib/live_fetch.js');

const CONTROLLERS = {
    'board-live': '../../assets/controllers/board_live_controller.js',
    'card-live': '../../assets/controllers/card_live_controller.js',
    'dashboard-live': '../../assets/controllers/dashboard_live_controller.js',
    'inbox-live': '../../assets/controllers/inbox_live_controller.js',
    'repository-checks-live':
        '../../assets/controllers/repository_checks_live_controller.js',
    'review-draft-live':
        '../../assets/controllers/review_draft_live_controller.js',
};

const LOST = {
    status: 410,
    headers: { get: (name) => (name === 'X-Loupe-Live-Lost' ? 'gone' : null) },
};

let application;

beforeEach(() => {
    reset();
    live.subscriptions.length = 0;
});

afterEach(() => {
    application?.stop();
    document.body.replaceChildren();
});

it.each(Object.entries(CONTROLLERS))(
    'the %s controller drops its live subscriptions when access is lost',
    async (identifier, path) => {
        const { default: Controller } = await import(path);
        document.body.innerHTML = `<main><div data-controller="${identifier}"></div></main>`;
        application = new Application();
        application.register(identifier, Controller);
        application.start();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(live.subscriptions.length).toBeGreaterThan(0);
        expect(live.subscriptions.some((s) => s.removed)).toBe(false);

        expect(handleLostAccess(LOST)).toBe(true);

        expect(live.subscriptions.every((s) => s.removed)).toBe(true);
    },
);
