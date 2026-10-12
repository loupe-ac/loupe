/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it, vi } from 'vitest';
import InboxAnchorController from '../../assets/controllers/inbox_anchor_controller.js';

let application;
let replace;

async function mount(href, markup = '') {
    const url = new URL(href, 'https://loupe.test');
    replace = vi.fn();
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { href: url.href, hash: url.hash, replace },
    });
    document.body.innerHTML =
        markup + '<div data-controller="inbox-anchor"></div>';
    application = Application.start();
    application.register('inbox-anchor', InboxAnchorController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    application?.stop();
    document.body.replaceChildren();
});

it('rewrites an old item fragment to the item query and drops the fragment', async () => {
    await mount('/projects/p/inbox#inbox-item-4');

    expect(replace).toHaveBeenCalledOnce();
    const target = new URL(replace.mock.calls[0][0], 'https://loupe.test');
    expect(target.searchParams.get('item')).toBe('4');
    expect(target.hash).toBe('');
});

it('rewrites the fragment even when the page holds the item, so the server picks the ask', async () => {
    await mount(
        '/projects/p/inbox#inbox-item-4',
        '<section id="inbox-item-4"></section>',
    );

    expect(replace).toHaveBeenCalledOnce();
});

it('keeps the rest of the address', async () => {
    await mount('/projects/p/inbox?type=review&page=2#inbox-item-12');

    const target = new URL(replace.mock.calls[0][0], 'https://loupe.test');
    expect(target.searchParams.get('type')).toBe('review');
    expect(target.searchParams.get('page')).toBe('2');
    expect(target.searchParams.get('item')).toBe('12');
});

it('ignores a fragment that names no item', async () => {
    await mount('/projects/p/inbox#main-content');

    expect(replace).not.toHaveBeenCalled();
});

it('ignores a fragment with text after the item number', async () => {
    await mount('/projects/p/inbox#inbox-item-4-extra');

    expect(replace).not.toHaveBeenCalled();
});
