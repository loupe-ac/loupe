/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import TopbarSearchController from '../../assets/controllers/topbar_search_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const WIDGET = `<div class="lp-topsearch" data-controller="topbar-search" data-topbar-search-url-value="/projects/p1/search">
    <button type="button" data-topbar-search-target="icon" aria-expanded="false">Search</button>
    <form data-topbar-search-target="form">
        <input type="search" name="query" data-topbar-search-target="field">
    </form>
    <div data-topbar-search-target="panel" hidden>
        <p data-topbar-search-target="status" hidden>Searching</p>
        <div data-topbar-search-target="error" hidden>Failed</div>
        <turbo-frame id="topbar-search-results" data-topbar-search-target="frame"></turbo-frame>
    </div>
</div>`;

async function mount(extra = '') {
    document.body.innerHTML = WIDGET + extra;
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller~="topbar-search"]'),
        'topbar-search',
    );
}

function type(text) {
    controller.fieldTarget.value = text;
    controller.input();
}

function answer(query, rows = '') {
    const frame = controller.frameTarget;
    frame.innerHTML = `<div data-search-query="${query}">${rows}</div>`;
    controller.loaded();
}

function beforeRender(query) {
    const newFrame = document.createElement('turbo-frame');
    newFrame.innerHTML = `<div data-search-query="${query}"></div>`;
    const render = vi.fn();
    const event = new CustomEvent('turbo:before-frame-render', {
        detail: { newFrame, render },
    });
    controller.beforeRender(event);
    return { event, render };
}

beforeEach(async () => {
    application = Application.start();
    application.register('topbar-search', TopbarSearchController);
    await mount();
});

afterEach(async () => {
    vi.useRealTimers();
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.restoreAllMocks();
});

it('waits 300 ms after the last key before it asks the server', async () => {
    vi.useFakeTimers();
    type('pa');
    type('pag');
    await vi.advanceTimersByTimeAsync(299);
    expect(controller.frameTarget.getAttribute('src')).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(controller.frameTarget.getAttribute('src')).toBe(
        '/projects/p1/search?query=pag',
    );
});

it('shows the progress line while a request runs and hides it when the answer matches', async () => {
    vi.useFakeTimers();
    type('pag');
    expect(controller.panelTarget.hidden).toBe(false);
    expect(controller.statusTarget.hidden).toBe(false);
    await vi.advanceTimersByTimeAsync(300);
    answer('pag');
    expect(controller.statusTarget.hidden).toBe(true);
});

it('skips the render of a stale answer that arrives after a newer query', () => {
    type('paging');
    const stale = beforeRender('pag');
    expect(stale.event.detail.render).not.toBe(stale.render);
    stale.event.detail.render();
    expect(stale.render).not.toHaveBeenCalled();
    expect(stale.event.defaultPrevented).toBe(false);

    const current = beforeRender('paging');
    expect(current.event.detail.render).toBe(current.render);
});

it('keeps the old rows and the progress line after a stale answer loads', () => {
    answer('pag', '<a data-search-kind="card">One</a>');
    type('paging');
    controller.loaded();
    expect(controller.statusTarget.hidden).toBe(false);
    expect(controller.frameTarget.querySelector('a')).not.toBeNull();
});

it('compares the answer with the trimmed field text', () => {
    type('  pag  ');
    expect(beforeRender('pag').event.detail.render).toBeDefined();
    const matching = beforeRender('pag');
    expect(matching.event.detail.render).toBe(matching.render);
});

it('shows an error with a retry that asks again', async () => {
    vi.useFakeTimers();
    type('pag');
    await vi.advanceTimersByTimeAsync(300);
    const event = new CustomEvent('turbo:frame-missing', { cancelable: true });
    controller.failed(event);
    expect(event.defaultPrevented).toBe(true);
    expect(controller.errorTarget.hidden).toBe(false);
    expect(controller.statusTarget.hidden).toBe(true);

    const reload = vi.fn();
    controller.frameTarget.reload = reload;
    controller.retry();
    expect(controller.errorTarget.hidden).toBe(true);
    expect(reload).toHaveBeenCalledOnce();
});

it('opens a card row in the drawer on the board', async () => {
    await mount('<div data-controller="board-view"></div>');
    answer(
        'pag',
        '<a data-search-kind="card" data-turbo-frame="_top">Card</a><a data-search-kind="document" data-turbo-frame="_top">Doc</a>',
    );
    const [card, document_] = controller.frameTarget.querySelectorAll('a');
    expect(card.dataset.turboFrame).toBe('card-drawer-frame');
    expect(card.hasAttribute('data-card-drawer-opener')).toBe(true);
    expect(document_.dataset.turboFrame).toBe('_top');
    expect(document_.hasAttribute('data-card-drawer-opener')).toBe(false);
});

it('opens a card row as a full visit off the board', () => {
    answer(
        'pag',
        '<a data-search-kind="card" data-turbo-frame="card-drawer-frame" data-card-drawer-opener>Card</a>',
    );
    const row = controller.frameTarget.querySelector('a');
    expect(row.dataset.turboFrame).toBe('_top');
    expect(row.hasAttribute('data-card-drawer-opener')).toBe(false);
});

it('opens the icon form on Ctrl+K when the icon shows, and focuses the field when it does not', () => {
    const press = () => {
        const event = new KeyboardEvent('keydown', {
            key: 'k',
            ctrlKey: true,
            cancelable: true,
        });
        controller.shortcut(event);
        return event;
    };
    expect(press().defaultPrevented).toBe(true);
    expect(controller.element.classList.contains('lp-topsearch--open')).toBe(
        true,
    );
    expect(controller.iconTarget.getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(controller.fieldTarget);

    controller.close();
    controller.iconTarget.style.display = 'none';
    press();
    expect(controller.element.classList.contains('lp-topsearch--open')).toBe(
        false,
    );
    expect(document.activeElement).toBe(controller.fieldTarget);
});

it('closes on Escape, Cancel and a click outside, and keeps the field text', () => {
    type('pag');
    controller.openIcon();
    controller.shortcut(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(controller.element.classList.contains('lp-topsearch--open')).toBe(
        false,
    );
    expect(controller.panelTarget.hidden).toBe(true);
    expect(controller.fieldTarget.value).toBe('pag');

    controller.openIcon();
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(controller.element.classList.contains('lp-topsearch--open')).toBe(
        false,
    );

    controller.openIcon();
    controller.close();
    expect(controller.panelTarget.hidden).toBe(true);
});

it('hides the panel when the field empties', () => {
    type('pag');
    expect(controller.panelTarget.hidden).toBe(false);
    type('   ');
    expect(controller.panelTarget.hidden).toBe(true);
});

it('asks for the typed query when the field regains focus with an older answer', () => {
    answer('pag');
    controller.fieldTarget.value = 'pagi';
    controller.focus();
    expect(controller.frameTarget.getAttribute('src')).toContain('query=pagi');
    expect(controller.statusTarget.hidden).toBe(false);
});
