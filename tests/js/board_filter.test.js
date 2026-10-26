/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardFilterController from '../../assets/controllers/board_filter_controller.js';

let application;

beforeEach(() => {
    application = Application.start();
    application.register('board-filter', BoardFilterController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

async function mount() {
    document.body.innerHTML = `<main><div data-controller="board-filter">
        <div class="lp-board-toolbar"><input data-board-filter-target="query" data-action="input->board-filter#filter blur->board-filter#closeSearch"></div>
        <span data-board-filter-target="count" data-one="1 card" data-many="%count% cards">2 cards</span>
        <p data-board-filter-target="empty" hidden>No card matches.</p>
        <section class="lp-board-lane" data-lane="epic-a">
            <header data-board-filter-target="laneHead" data-card-title="Checkout epic">#1 Checkout epic</header>
            <article data-board-filter-target="card" data-card-title="Pay with a card">Pay</article>
        </section>
        <section class="lp-board-lane" data-lane="other">
            <article data-board-filter-target="card" data-card-title="Fix the footer">Footer</article>
        </section>
    </div></main>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function search(text) {
    const query = document.querySelector('[data-board-filter-target="query"]');
    query.value = text;
    query.dispatchEvent(new Event('input', { bubbles: true }));
}

const count = () =>
    document.querySelector('[data-board-filter-target="count"]').textContent;
const empty = () =>
    document.querySelector('[data-board-filter-target="empty"]').hidden;

it('counts an epic whose lane title matches once and keeps its lane', async () => {
    await mount();

    search('checkout');

    expect(count()).toBe('1 card');
    expect(empty()).toBe(true);
    expect(document.querySelector('[data-lane="epic-a"]').hidden).toBe(false);
    expect(
        document.querySelector('[data-board-filter-target="laneHead"]').hidden,
    ).toBe(false);
    for (const card of document.querySelectorAll(
        '[data-board-filter-target="card"]',
    )) {
        expect(card.hidden).toBe(true);
    }
});

it('counts matching cards and a matching lane title together', async () => {
    await mount();

    search('c');

    expect(count()).toBe('2 cards');
});

it('opens a collapsed lane while a search matches one of its cards', async () => {
    await mount();
    const lane = document.querySelector('[data-lane="epic-a"]');
    lane.classList.add('lp-board-lane--collapsed');
    window.localStorage.setItem('collapsed', 'untouched');

    search('pay');
    expect(lane.classList.contains('lp-board-lane--revealed')).toBe(true);

    search('footer');
    expect(lane.classList.contains('lp-board-lane--revealed')).toBe(false);

    search('pay');
    search('');
    expect(lane.classList.contains('lp-board-lane--revealed')).toBe(false);
    expect(lane.classList.contains('lp-board-lane--collapsed')).toBe(true);
    expect(window.localStorage.getItem('collapsed')).toBe('untouched');
    expect(window.localStorage.length).toBe(1);
    window.localStorage.clear();
});

it('keeps a lane header whose title does not match, and says when nothing matches', async () => {
    await mount();

    search('nothing like this');

    expect(count()).toBe('0 cards');
    expect(empty()).toBe(false);
    expect(
        document.querySelector('[data-board-filter-target="laneHead"]').hidden,
    ).toBe(false);
});

const toolbar = () => document.querySelector('.lp-board-toolbar');
const field = () =>
    document.querySelector('[data-board-filter-target="query"]');

it('opens the search row and focuses the field', async () => {
    await mount();

    application
        .getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="board-filter"]'),
            'board-filter',
        )
        .openSearch();

    expect(toolbar().hasAttribute('data-search-open')).toBe(true);
    expect(document.activeElement).toBe(field());
});

it('closes the search row when the field loses focus while empty', async () => {
    await mount();
    application
        .getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="board-filter"]'),
            'board-filter',
        )
        .openSearch();

    field().blur();

    expect(toolbar().hasAttribute('data-search-open')).toBe(false);
});

it('keeps the search row open when the field loses focus with text', async () => {
    await mount();
    application
        .getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="board-filter"]'),
            'board-filter',
        )
        .openSearch();
    search('footer');

    field().blur();

    expect(toolbar().hasAttribute('data-search-open')).toBe(true);
});

it('opens the search row of a new toolbar that gets a saved query back', async () => {
    document.body.innerHTML = '<div id="page"></div>';
    const page = document.getElementById('page');
    const board = `<div data-controller="board-filter">
        <div class="lp-board-toolbar"><input data-board-filter-target="query" data-action="input->board-filter#filter"></div>
        <span data-board-filter-target="count" data-one="1 card" data-many="%count% cards">1 card</span>
        <p data-board-filter-target="empty" hidden></p>
        <article data-board-filter-target="card" data-card-title="Fix the footer">Footer</article>
    </div>`;
    page.innerHTML = board;
    await new Promise((resolve) => setTimeout(resolve, 0));
    search('footer');

    document.dispatchEvent(new Event('turbo:before-frame-render'));
    page.innerHTML = board;
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(field().value).toBe('footer');
    expect(toolbar().hasAttribute('data-search-open')).toBe(true);
});
