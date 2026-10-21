/** @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { beforeSend, track } from '../../assets/lib/analytics.js';

function tag(paths) {
    document.head.innerHTML = `<script data-public-paths='${paths}'></script>`;
}

beforeEach(() => tag('["/","/privacy","/waitlist"]'));

afterEach(() => {
    document.head.replaceChildren();
    vi.unstubAllGlobals();
});

it('registers the guard on window for the tag to find', () => {
    expect(window.loupeAnalyticsBeforeSend).toBe(beforeSend);
});

it('lets a public path through unchanged', () => {
    const payload = { url: '/privacy?utm_source=hn' };
    expect(beforeSend('event', payload)).toBe(payload);
});

it('lets the home page through', () => {
    const payload = { url: '/' };
    expect(beforeSend('pageview', payload)).toBe(payload);
});

it('drops a path that is not public', () => {
    expect(beforeSend('pageview', { url: '/projects/1/board' })).toBeNull();
});

it('drops a path that only starts like a public one', () => {
    expect(beforeSend('pageview', { url: '/privacy-secret' })).toBeNull();
});

it('reads the path of an absolute url', () => {
    expect(
        beforeSend('pageview', { url: 'https://loupe.ac/waitlist' }),
    ).not.toBeNull();
    expect(
        beforeSend('pageview', { url: 'https://app.loupe.ac/account' }),
    ).toBeNull();
});

it('removes the waitlist ticket and keeps the campaign tags', () => {
    expect(
        beforeSend('pageview', {
            url: '/waitlist?joined=1&t=0123456789abcdef0123456789abcdef&utm_source=hn',
            title: 'Joined',
        }),
    ).toEqual({ url: '/waitlist?joined=1&utm_source=hn', title: 'Joined' });
});

it('removes the waitlist ticket from an absolute url', () => {
    expect(
        beforeSend('event', {
            url: 'https://loupe.ac/waitlist?joined=1&t=0123456789abcdef0123456789abcdef',
        }).url,
    ).toBe('https://loupe.ac/waitlist?joined=1');
});

it('drops a payload with no url', () => {
    expect(beforeSend('event', {})).toBeNull();
});

it('drops every send when the list is missing or broken', () => {
    document.head.replaceChildren();
    expect(beforeSend('pageview', { url: '/privacy' })).toBeNull();
    tag('not json');
    expect(beforeSend('pageview', { url: '/privacy' })).toBeNull();
});

it('sends an event through umami when the tracker is loaded', () => {
    const umami = { track: vi.fn() };
    vi.stubGlobal('umami', umami);
    track('waitlist-click', { button: 'hero' });
    expect(umami.track).toHaveBeenCalledWith('waitlist-click', {
        button: 'hero',
    });
});

it('does nothing when the tracker is not loaded', () => {
    expect(() => track('waitlist-click', { button: 'hero' })).not.toThrow();
});
