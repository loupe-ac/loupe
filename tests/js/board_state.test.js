/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardViewController from '../../assets/controllers/board_view_controller.js';

let application;

beforeEach(() => {
    application = Application.start();
    application.register('board-view', BoardViewController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await Promise.resolve();
    application.stop();
});

function boardHtml(titles = ['Fix login', 'Write docs']) {
    const cards = titles
        .map(
            (title) =>
                `<article data-board-view-target="card" data-card-title="${title}"></article>`,
        )
        .join('');

    return `<div id="board" data-controller="board-view" data-board-view-list-url-value="/board/list" data-action="board:placed@document->board-view#updateCount">
        <span data-board-view-target="count" data-one="1 card" data-many="%count% cards"></span>
        <button data-board-view-target="boardButton" aria-pressed="true"></button>
        <button data-board-view-target="listButton" aria-pressed="false"></button>
        <div data-board-view-target="board">${cards}</div>
        <turbo-frame data-board-view-target="list" hidden></turbo-frame>
    </div>`;
}

async function mount(titles) {
    document.body.innerHTML = `<div class="lp-split-region">${boardHtml(titles)}</div>`;
    await Promise.resolve();
    return document.querySelector('.lp-split-region');
}

async function rerender(frame) {
    frame.dispatchEvent(
        new CustomEvent('turbo:before-frame-render', { bubbles: true }),
    );
    frame.innerHTML = boardHtml();
    await Promise.resolve();
}

async function replaceBoard() {
    const board = document.getElementById('board');
    board.dispatchEvent(
        new CustomEvent('turbo:before-stream-render', { bubbles: true }),
    );
    const template = document.createElement('template');
    template.innerHTML = boardHtml();
    board.replaceWith(template.content.firstElementChild);
    await Promise.resolve();
}

const countText = () =>
    document.querySelector('[data-board-view-target="count"]').textContent;

function showList() {
    application
        .getControllerForElementAndIdentifier(
            document.getElementById('board'),
            'board-view',
        )
        .showList();
}

it('counts the cards of the board when it connects', async () => {
    await mount();

    expect(countText()).toBe('2 cards');
});

it('uses the singular for one card', async () => {
    await mount(['Fix login']);

    expect(countText()).toBe('1 card');
});

it('counts a card that arrives after the board connected', async () => {
    await mount();
    const card = document.createElement('article');
    card.dataset.boardViewTarget = 'card';

    document.querySelector('[data-board-view-target="board"]').append(card);
    await Promise.resolve();
    await Promise.resolve();

    expect(countText()).toBe('3 cards');
});

it('counts again when a card leaves the board', async () => {
    await mount();

    document.querySelector('[data-board-view-target="card"]').remove();
    await Promise.resolve();
    await Promise.resolve();

    expect(countText()).toBe('1 card');
});

it('counts again on a live placement', async () => {
    await mount();
    document
        .querySelector('[data-board-view-target="count"]')
        .replaceChildren();

    document.dispatchEvent(new CustomEvent('board:placed'));

    expect(countText()).toBe('2 cards');
});

it('keeps the list view when a stream replaces the board', async () => {
    await mount();
    showList();
    const oldBoard = document.getElementById('board');

    await replaceBoard();

    expect(document.getElementById('board')).not.toBe(oldBoard);
    expect(
        document
            .querySelector('[data-board-view-target="list"]')
            .getAttribute('src'),
    ).toBe('/board/list');
    expect(
        document.querySelector('[data-board-view-target="list"]').hidden,
    ).toBe(false);
});

it('keeps the list view across a re-render of the board', async () => {
    const frame = await mount();
    showList();

    await rerender(frame);

    expect(
        document.querySelector('[data-board-view-target="board"]').hidden,
    ).toBe(true);
    expect(
        document.querySelector('[data-board-view-target="list"]').hidden,
    ).toBe(false);
    expect(
        document
            .querySelector('[data-board-view-target="listButton"]')
            .getAttribute('aria-pressed'),
    ).toBe('true');
});

it('starts a new board frame with the board view', async () => {
    const frame = await mount();
    showList();
    frame.remove();
    await Promise.resolve();

    await mount();

    expect(
        document.querySelector('[data-board-view-target="list"]').hidden,
    ).toBe(true);
});
