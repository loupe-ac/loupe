/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import InboxNavigationController from '../../assets/controllers/inbox_navigation_controller.js';

let application;

async function mount() {
    document.body.innerHTML =
        '<div data-controller="inbox-navigation"><turbo-frame id="inbox-view"><div data-inbox-scroll><a class="lp-inbox-request" id="first" aria-current="true" href="#"></a><a class="lp-inbox-request" id="second" href="#"><span></span></a></div></turbo-frame><turbo-frame id="other"></turbo-frame></div>';
    application = Application.start();
    application.register('inbox-navigation', InboxNavigationController);
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

it('marks the picked row as current before the frame loads', async () => {
    await mount();

    document
        .querySelector('#second span')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));

    expect(document.getElementById('second').getAttribute('aria-current')).toBe(
        'true',
    );
    expect(document.getElementById('first').hasAttribute('aria-current')).toBe(
        false,
    );
});

it('leaves the current row alone when a row opens in a new tab', async () => {
    await mount();

    document.getElementById('second').dispatchEvent(
        new MouseEvent('click', {
            bubbles: true,
            button: 0,
            metaKey: true,
        }),
    );

    expect(document.getElementById('first').getAttribute('aria-current')).toBe(
        'true',
    );
    expect(document.getElementById('second').hasAttribute('aria-current')).toBe(
        false,
    );
});
