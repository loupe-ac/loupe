/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import InboxScrollController from '../../assets/controllers/inbox_scroll_controller.js';

let application;

async function mount() {
    document.body.innerHTML =
        '<div data-controller="inbox-scroll"><turbo-frame id="inbox-view"><div data-inbox-scroll></div></turbo-frame><turbo-frame id="other"></turbo-frame></div>';
    application = Application.start();
    application.register('inbox-scroll', InboxScrollController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function render(frameId, scrollTop) {
    const frame = document.getElementById(frameId);
    frame.dispatchEvent(
        new CustomEvent('turbo:before-frame-render', { bubbles: true }),
    );
    frame.innerHTML = '<div data-inbox-scroll></div>';
    frame.querySelector('[data-inbox-scroll]').scrollTop = scrollTop;
    frame.dispatchEvent(
        new CustomEvent('turbo:frame-render', { bubbles: true }),
    );
}

afterEach(() => {
    application?.stop();
    document.body.replaceChildren();
});

it('gives the new request list the scroll of the old one', async () => {
    await mount();
    document.querySelector('[data-inbox-scroll]').scrollTop = 240;

    render('inbox-view', 0);

    expect(document.querySelector('[data-inbox-scroll]').scrollTop).toBe(240);
});

it('leaves the list alone when another frame renders', async () => {
    await mount();
    document.querySelector('[data-inbox-scroll]').scrollTop = 240;
    document
        .getElementById('other')
        .dispatchEvent(
            new CustomEvent('turbo:before-frame-render', { bubbles: true }),
        );
    document.querySelector('[data-inbox-scroll]').scrollTop = 10;
    document
        .getElementById('other')
        .dispatchEvent(
            new CustomEvent('turbo:frame-render', { bubbles: true }),
        );

    expect(document.querySelector('[data-inbox-scroll]').scrollTop).toBe(10);
});
