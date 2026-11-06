/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import InboxNavigationController from '../../assets/controllers/inbox_navigation_controller.js';

let application;

async function mount() {
    document.body.innerHTML =
        '<div data-controller="inbox-navigation"><turbo-frame id="inbox-view"><div data-inbox-scroll><a class="lp-list-row" id="first" aria-current="true" href="#"></a><a class="lp-list-row" id="second" href="#"><span></span></a></div></turbo-frame><turbo-frame id="other"></turbo-frame></div>';
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

it('shows the spinner from a row click until the view frame renders', async () => {
    await mount();
    const root = document.querySelector('[data-controller="inbox-navigation"]');

    document
        .getElementById('second')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
    expect(root.hasAttribute('data-inbox-loading')).toBe(true);

    render('inbox-view', 0);
    expect(root.hasAttribute('data-inbox-loading')).toBe(false);
});

it('hides the spinner when the request fails', async () => {
    await mount();
    const root = document.querySelector('[data-controller="inbox-navigation"]');

    document
        .getElementById('second')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
    document.dispatchEvent(new CustomEvent('turbo:fetch-request-error'));

    expect(root.hasAttribute('data-inbox-loading')).toBe(false);
});

it('shows no spinner when another frame renders', async () => {
    await mount();
    const root = document.querySelector('[data-controller="inbox-navigation"]');

    render('other', 0);

    expect(root.hasAttribute('data-inbox-loading')).toBe(false);
});

async function mountWithControls() {
    document.body.innerHTML =
        '<div data-controller="inbox-navigation"><turbo-frame id="inbox-view"><a class="lp-tabs__tab" id="tab-closed" data-turbo-frame="inbox-view" data-inbox-side="closed" href="#"></a><form id="search-form"><input id="inbox-search" name="q"></form><a class="lp-filter-clear" id="clear" data-turbo-frame="inbox-view" href="#"></a><a class="lp-inbox-filter__button" data-turbo-frame="inbox-view" data-inbox-type="all" href="#"></a><a class="lp-inbox-filter__button" data-turbo-frame="inbox-view" data-inbox-type="question" href="#"></a><div data-inbox-scroll><a class="lp-list-row" id="second" href="#"></a></div></turbo-frame></div>';
    application = Application.start();
    application.register('inbox-navigation', InboxNavigationController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function renderView(html) {
    const frame = document.getElementById('inbox-view');
    frame.dispatchEvent(
        new CustomEvent('turbo:before-frame-render', { bubbles: true }),
    );
    frame.innerHTML = html;
    frame.dispatchEvent(
        new CustomEvent('turbo:frame-render', { bubbles: true }),
    );
}

const CONTROLS =
    '<a class="lp-tabs__tab" data-turbo-frame="inbox-view" data-inbox-side="closed" href="#"></a><form><input id="inbox-search" name="q"></form><a class="lp-inbox-filter__button" data-turbo-frame="inbox-view" data-inbox-type="question" href="#"></a>';

it('moves focus to the title of the picked request', async () => {
    await mountWithControls();
    document
        .getElementById('second')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));

    renderView(
        `${CONTROLS}<h2 class="lp-inbox-ask__title" tabindex="-1"></h2>`,
    );

    expect(document.activeElement.className).toBe('lp-inbox-ask__title');
});

it('returns focus to the tab that was used', async () => {
    await mountWithControls();
    document
        .getElementById('tab-closed')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));

    renderView(CONTROLS);

    expect(document.activeElement.dataset.inboxSide).toBe('closed');
});

it('returns focus to the type filter that was used', async () => {
    await mountWithControls();
    document
        .querySelector('[data-inbox-type="question"]')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));

    renderView(CONTROLS);

    expect(document.activeElement.dataset.inboxType).toBe('question');
});

it('returns focus to the search field after a search submits', async () => {
    await mountWithControls();
    document
        .getElementById('search-form')
        .dispatchEvent(new Event('submit', { bubbles: true }));

    renderView(CONTROLS);

    expect(document.activeElement.id).toBe('inbox-search');
});

it('returns focus to the search field after the Clear link', async () => {
    await mountWithControls();
    document
        .getElementById('clear')
        .dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));

    renderView(CONTROLS);

    expect(document.activeElement.id).toBe('inbox-search');
});

it('moves no focus when a render follows no action of the owner', async () => {
    await mountWithControls();

    renderView(CONTROLS);

    expect(document.activeElement).toBe(document.body);
});
