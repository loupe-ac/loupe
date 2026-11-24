/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DecisionsSeenController from '../../assets/controllers/decisions_seen_controller.js';

let application;
let submitted;

const PANEL = (
    hidden,
) => `<section data-controller="decisions-seen"${hidden ? ' hidden' : ''}>
    <form hidden method="post" action="/seen" data-decisions-seen-target="form"><input name="_csrf_token" value="t"></form>
</section>`;

async function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('decisions-seen', DecisionsSeenController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
    submitted = vi.fn((event) => event.preventDefault());
    document.addEventListener('submit', submitted);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    document.removeEventListener('submit', submitted);
});

it('posts when the panel is open on load', async () => {
    await mount(PANEL(false));

    expect(submitted).toHaveBeenCalledTimes(1);
    expect(submitted.mock.calls[0][0].target.getAttribute('action')).toBe(
        '/seen',
    );
});

it('posts nothing while the panel is hidden', async () => {
    await mount(PANEL(true));

    expect(submitted).not.toHaveBeenCalled();
});

it('posts when the tab opens the panel', async () => {
    await mount(PANEL(true));

    document.querySelector('section').hidden = false;
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(submitted).toHaveBeenCalledTimes(1);
});
