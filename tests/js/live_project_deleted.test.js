/** @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler) => {
        const subscription = { types, handler, removed: false };
        live.subscriptions.push(subscription);

        return () => {
            subscription.removed = true;
        };
    },
}));

const { registerProjectDeleted } =
    await import('../../assets/lib/live_project_deleted.js');
const { reset } = await import('../../assets/lib/live_fetch.js');

const PROJECT = '0199aaaa-0000-7000-8000-000000000001';

function deleted(change = {}) {
    return live.subscriptions[0].handler({
        type: 'project.deleted',
        projectId: PROJECT,
        own: false,
        local: false,
        ...change,
    });
}

beforeEach(() => {
    reset();
    document.body.innerHTML = `<template data-live-band-template="project-deleted"><div class="lp-flash">Deleted</div></template><main id="main-content"><div data-live-content>Content</div></main><div hidden data-live-project="${PROJECT}"></div>`;
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            status: 410,
            ok: false,
            headers: {
                get: (name) =>
                    name === 'X-Loupe-Live-Lost' ? 'project-deleted' : null,
            },
        })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
});

it('registers one handler for the deletion signal at load, and only one', () => {
    expect(live.subscriptions).toHaveLength(1);
    expect(live.subscriptions[0].types).toBe('project.deleted');
    registerProjectDeleted();
    expect(live.subscriptions).toHaveLength(1);
});

it('reads the current page once with the live header and shows the band', async () => {
    await deleted();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][1].headers['X-Loupe-Live-Refresh']).toBe('1');
    expect(document.querySelector('[data-live-content]').hidden).toBe(true);
    expect(document.querySelectorAll('[data-live-lost-band]')).toHaveLength(1);
});

it('ignores another project', async () => {
    await deleted({ projectId: 'other' });
    expect(fetch).not.toHaveBeenCalled();
});

it('ignores a page with no project', async () => {
    document.querySelector('[data-live-project]').remove();
    await deleted();
    expect(fetch).not.toHaveBeenCalled();
});

it('ignores its own change', async () => {
    await deleted({ own: true });
    expect(fetch).not.toHaveBeenCalled();
});

it('shows nothing when the read fails', async () => {
    fetch.mockRejectedValueOnce(new Error('offline'));
    await deleted();
    expect(document.querySelector('[data-live-lost-band]')).toBeNull();
});
