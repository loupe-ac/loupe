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
        <span data-topbar-search-target="progress" hidden></span>
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
    expect(controller.progressTarget.hidden).toBe(false);
    await vi.advanceTimersByTimeAsync(300);
    answer('pag');
    expect(controller.statusTarget.hidden).toBe(true);
    expect(controller.progressTarget.hidden).toBe(true);
});

it('keeps the old rows under the progress line for a second query, with no status line', async () => {
    vi.useFakeTimers();
    type('pag');
    await vi.advanceTimersByTimeAsync(300);
    answer('pag', '<a data-search-kind="card">One</a>');
    type('pagi');
    expect(controller.progressTarget.hidden).toBe(false);
    expect(controller.statusTarget.hidden).toBe(true);
    expect(controller.frameTarget.querySelector('a')).not.toBeNull();

    controller.frameTarget.reload = vi.fn();
    controller.focus();
    controller.retry();
    expect(controller.statusTarget.hidden).toBe(true);

    await vi.advanceTimersByTimeAsync(300);
    answer('pagi');
    expect(controller.progressTarget.hidden).toBe(true);
});

it('hides the progress line on a failure, on close and when the field empties', async () => {
    vi.useFakeTimers();
    type('pag');
    await vi.advanceTimersByTimeAsync(300);
    controller.failed(
        new CustomEvent('turbo:frame-missing', { cancelable: true }),
    );
    expect(controller.progressTarget.hidden).toBe(true);

    type('pagi');
    controller.close();
    expect(controller.progressTarget.hidden).toBe(true);

    type('pagin');
    type('');
    expect(controller.progressTarget.hidden).toBe(true);
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
    expect(controller.progressTarget.hidden).toBe(false);
    expect(controller.statusTarget.hidden).toBe(true);
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

it('shows the error for a failed answer that is not HTML, and not for a good answer', async () => {
    vi.useFakeTimers();
    type('pag');
    await vi.advanceTimersByTimeAsync(300);
    const response = (succeeded) =>
        new CustomEvent('turbo:before-fetch-response', {
            detail: { fetchResponse: { succeeded } },
        });
    controller.response(response(true));
    expect(controller.errorTarget.hidden).toBe(true);

    controller.response(response(false));
    expect(controller.errorTarget.hidden).toBe(false);
    expect(controller.statusTarget.hidden).toBe(true);
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

it('leaves an empty focused field on Escape', () => {
    controller.iconTarget.style.display = 'none';
    controller.shortcut(
        new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }),
    );
    expect(document.activeElement).toBe(controller.fieldTarget);
    expect(controller.panelTarget.hidden).toBe(true);

    const escape = new KeyboardEvent('keydown', {
        key: 'Escape',
        cancelable: true,
    });
    controller.shortcut(escape);
    expect(escape.defaultPrevented).toBe(true);
    expect(document.activeElement).not.toBe(controller.fieldTarget);
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
    expect(controller.progressTarget.hidden).toBe(false);
});

it('ignores a failure of an older query after the field changes', async () => {
    vi.useFakeTimers();
    type('pag');
    await vi.advanceTimersByTimeAsync(300);
    type('pagi');
    controller.response(
        new CustomEvent('turbo:before-fetch-response', {
            detail: { fetchResponse: { succeeded: false } },
        }),
    );
    controller.failed(
        new CustomEvent('turbo:fetch-request-error', { cancelable: true }),
    );
    expect(controller.errorTarget.hidden).toBe(true);
    expect(controller.statusTarget.hidden).toBe(false);

    await vi.advanceTimersByTimeAsync(300);
    controller.failed(
        new CustomEvent('turbo:fetch-request-error', { cancelable: true }),
    );
    expect(controller.errorTarget.hidden).toBe(false);
});

it('reopens the panel and asks again when the field regains focus after a failed search', async () => {
    vi.useFakeTimers();
    type('pag');
    await vi.advanceTimersByTimeAsync(300);
    controller.failed(
        new CustomEvent('turbo:frame-missing', { cancelable: true }),
    );
    document.body.click();
    expect(controller.panelTarget.hidden).toBe(true);

    const reload = vi.fn();
    controller.frameTarget.reload = reload;
    controller.focus();
    expect(controller.panelTarget.hidden).toBe(false);
    expect(controller.statusTarget.hidden).toBe(false);
    expect(reload).toHaveBeenCalledOnce();
});

it('asks once when the field regains focus while the wait after a key runs', async () => {
    vi.useFakeTimers();
    type('pag');
    controller.focus();
    expect(controller.frameTarget.getAttribute('src')).toContain('query=pag');
    const reload = vi.fn();
    controller.frameTarget.reload = reload;
    await vi.advanceTimersByTimeAsync(300);
    expect(reload).not.toHaveBeenCalled();
});

function key(name) {
    const event = new KeyboardEvent('keydown', {
        key: name,
        cancelable: true,
    });
    controller.navigate(event);
    return event;
}

const ROWS = `<a role="option" href="/c/1" data-search-kind="card">One</a>
    <a role="option" href="/d/2" data-search-kind="document">Two</a>
    <a href="/search?query=pag" data-search-all>All</a>`;

function selected() {
    return [
        ...controller.frameTarget.querySelectorAll('[aria-selected="true"]'),
    ].map((option) => option.textContent);
}

it('moves the active row with the arrow keys, the footer included, and keeps the focus in the field', () => {
    type('pag');
    answer('pag', ROWS);
    controller.fieldTarget.focus();

    expect(key('ArrowDown').defaultPrevented).toBe(true);
    expect(selected()).toEqual(['One']);
    expect(
        controller.fieldTarget.getAttribute('aria-activedescendant'),
    ).toBe('topbar-search-option-0');
    key('ArrowDown');
    key('ArrowDown');
    expect(selected()).toEqual(['All']);
    key('ArrowDown');
    expect(selected()).toEqual([]);
    expect(controller.fieldTarget.hasAttribute('aria-activedescendant')).toBe(
        false,
    );
    key('ArrowUp');
    expect(selected()).toEqual(['All']);
    key('ArrowUp');
    expect(selected()).toEqual(['Two']);
    expect(document.activeElement).toBe(controller.fieldTarget);
});

it('opens the active row on Enter, and leaves Enter to the form with no active row', () => {
    type('pag');
    answer('pag', ROWS);
    const clicked = vi.fn((event) => event.preventDefault());
    controller.frameTarget
        .querySelector('[data-search-kind="document"]')
        .addEventListener('click', clicked);

    expect(key('Enter').defaultPrevented).toBe(false);
    key('ArrowDown');
    key('ArrowDown');
    expect(key('Enter').defaultPrevented).toBe(true);
    expect(clicked).toHaveBeenCalledTimes(1);
});

it('clears the active row on the first Escape and closes on the second', () => {
    type('pag');
    answer('pag', ROWS);
    controller.openIcon();
    key('ArrowDown');

    const first = key('Escape');
    controller.shortcut(first);
    expect(selected()).toEqual([]);
    expect(controller.panelTarget.hidden).toBe(false);
    expect(controller.element.classList.contains('lp-topsearch--open')).toBe(
        true,
    );

    const second = key('Escape');
    controller.shortcut(second);
    expect(controller.panelTarget.hidden).toBe(true);
});

it('forgets the active row when the text changes or a new answer loads', () => {
    type('pag');
    answer('pag', ROWS);
    key('ArrowDown');
    type('pagi');
    expect(selected()).toEqual([]);

    answer('pagi', ROWS);
    key('ArrowDown');
    answer('pagi', ROWS);
    expect(selected()).toEqual([]);
    expect(controller.fieldTarget.hasAttribute('aria-activedescendant')).toBe(
        false,
    );
});

it('keeps the open icon form until its closing animation ends', () => {
    controller.openIcon();
    const style = vi
        .spyOn(window, 'getComputedStyle')
        .mockReturnValue({ animationName: 'lp-topsearch-conceal' });
    controller.close();
    const classes = controller.element.classList;
    expect(classes.contains('lp-topsearch--closing')).toBe(true);
    expect(classes.contains('lp-topsearch--open')).toBe(true);
    style.mockRestore();

    controller.formTarget.dispatchEvent(new Event('animationend'));
    expect(classes.contains('lp-topsearch--open')).toBe(false);
    expect(classes.contains('lp-topsearch--closing')).toBe(false);

    controller.openIcon();
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({
        animationName: 'lp-topsearch-conceal',
    });
    controller.close();
    controller.openIcon();
    controller.formTarget.dispatchEvent(new Event('animationend'));
    expect(classes.contains('lp-topsearch--open')).toBe(true);
    expect(classes.contains('lp-topsearch--closing')).toBe(false);
});
