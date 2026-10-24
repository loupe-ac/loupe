/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardColumnsNavController from '../../assets/controllers/board_columns_nav_controller.js';

let application;
let observed;
let scrollIntoView;

const COLUMNS = [
    ['c1', 'Next', 2],
    ['c2', 'In progress', 0],
    ['c3', 'Done', 5],
];

const column = ([id, label, count]) => `
    <section class="lp-board__column" data-column-id="${id}">
        <h2 class="lp-board__column-title">${label}</h2>
        <span class="lp-board__column-count">${count}</span>
    </section>`;

const markup = () => `
    <div id="board" data-controller="board-columns-nav">
        <div data-board-columns-nav-target="tabs">
            <div data-controller="overflow-cue-stub">
                <div class="lp-tabs"></div>
                <button class="lp-overflow-cue__arrow lp-overflow-cue__arrow--prev"></button>
                <button class="lp-overflow-cue__arrow lp-overflow-cue__arrow--next"></button>
            </div>
        </div>
        <div class="lp-board__columns" data-board-view-target="board">
            ${COLUMNS.map(column).join('')}
        </div>
    </div>`;

async function start() {
    document.body.innerHTML = markup();
    application = Application.start();
    application.register('board-columns-nav', BoardColumnsNavController);
    await vi.advanceTimersByTimeAsync(0);
}

const columns = () => [...document.querySelectorAll('.lp-board__column')];
const tabs = () => [...document.querySelectorAll('.lp-tabs__tab')];
const prev = () => document.querySelector('.lp-overflow-cue__arrow--prev');
const next = () => document.querySelector('.lp-overflow-cue__arrow--next');
const selected = () =>
    tabs().find((tab) => 'true' === tab.getAttribute('aria-selected'))?.dataset
        .columnId;

function inView(index) {
    observed(
        columns().map((target, position) => ({
            target,
            intersectionRatio: position === index ? 1 : 0,
        })),
    );
}

beforeEach(() => {
    vi.useFakeTimers();
    scrollIntoView = vi.fn();
    Element.prototype.scrollIntoView = scrollIntoView;
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            constructor(callback) {
                observed = callback;
            }
            observe() {}
            disconnect() {}
        },
    );
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    vi.stubGlobal('CSS', { escape: (value) => value });
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('builds one tab per column with its count, and selects the first', async () => {
    await start();
    expect(tabs().map((tab) => tab.textContent)).toEqual([
        'Next2',
        'In progress0',
        'Done5',
    ]);
    expect(selected()).toBe('c1');
});

it('hides the previous arrow at the first column and shows the next one', async () => {
    await start();
    expect(prev().hidden).toBe(true);
    expect(next().hidden).toBe(false);
});

it('follows a swipe: the column most in view becomes the current tab', async () => {
    await start();
    inView(1);
    expect(selected()).toBe('c2');
    expect(prev().hidden).toBe(false);
    expect(next().hidden).toBe(false);
    inView(2);
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
});

it('steps one column to the right on a step event', async () => {
    await start();
    document.getElementById('board').dispatchEvent(
        new CustomEvent('overflow-cue:step', {
            bubbles: true,
            detail: { direction: 1 },
        }),
    );
    expect(scrollIntoView).toHaveBeenCalledTimes(1);
    expect(scrollIntoView.mock.contexts[0]).toBe(columns()[1]);
    expect(scrollIntoView.mock.calls[0][0]).toMatchObject({ inline: 'start' });
    expect(selected()).toBe('c2');
    expect(prev().hidden).toBe(false);
});

it('steps from the column it is heading to, not the one it left', async () => {
    await start();
    const step = (direction) =>
        document.getElementById('board').dispatchEvent(
            new CustomEvent('overflow-cue:step', {
                bubbles: true,
                detail: { direction },
            }),
        );
    step(1);
    step(1);
    expect(scrollIntoView.mock.contexts[1]).toBe(columns()[2]);
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
});

it('never steps past either end', async () => {
    await start();
    document.getElementById('board').dispatchEvent(
        new CustomEvent('overflow-cue:step', {
            bubbles: true,
            detail: { direction: -1 },
        }),
    );
    expect(selected()).toBe('c1');
    expect(prev().hidden).toBe(true);
    for (let index = 0; index < 5; index++) {
        document.getElementById('board').dispatchEvent(
            new CustomEvent('overflow-cue:step', {
                bubbles: true,
                detail: { direction: 1 },
            }),
        );
    }
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
});

it('scrolls to the column of a tapped tab', async () => {
    await start();
    tabs()[2].click();
    expect(scrollIntoView.mock.contexts[0]).toBe(columns()[2]);
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
    expect(prev().hidden).toBe(false);
});

it('ignores the columns it passes while it scrolls, then settles on the view', async () => {
    await start();
    tabs()[2].click();
    inView(1);
    expect(selected()).toBe('c3');
    inView(2);
    vi.advanceTimersByTime(800);
    expect(selected()).toBe('c3');
});

it('keeps the counts of the tabs in step with the column counts', async () => {
    await start();
    document.querySelectorAll('.lp-board__column-count')[1].textContent = '4';
    await vi.advanceTimersByTimeAsync(0);
    expect(tabs()[1].textContent).toBe('In progress4');
});

it('rebuilds the tabs when the structure changes', async () => {
    await start();
    document
        .querySelector('.lp-board__columns')
        .insertAdjacentHTML('beforeend', column(['c4', 'Review', 1]));
    document.dispatchEvent(new CustomEvent('board:structure-changed'));
    expect(tabs().map((tab) => tab.dataset.columnId)).toEqual([
        'c1',
        'c2',
        'c3',
        'c4',
    ]);
    expect(selected()).toBe('c1');
});

it('reads the columns of the first lane on a board with lanes', async () => {
    document.body.innerHTML = `
        <div id="board" data-controller="board-columns-nav">
            <div data-board-columns-nav-target="tabs">
                <div class="lp-tabs"></div>
                <button class="lp-overflow-cue__arrow--prev lp-overflow-cue__arrow"></button>
                <button class="lp-overflow-cue__arrow--next lp-overflow-cue__arrow"></button>
            </div>
            <div class="lp-board__columns lp-board__columns--lanes" data-board-view-target="board">
                <div class="lp-board-lanes">
                    <section class="lp-board-lane"><div class="lp-board-lane__cells">
                        ${column(COLUMNS[0])}${column(COLUMNS[1])}
                    </div></section>
                    <section class="lp-board-lane"><div class="lp-board-lane__cells">
                        ${column(COLUMNS[0])}${column(COLUMNS[1])}
                    </div></section>
                </div>
            </div>
        </div>`;
    application = Application.start();
    application.register('board-columns-nav', BoardColumnsNavController);
    await vi.advanceTimersByTimeAsync(0);
    expect(tabs().map((tab) => tab.dataset.columnId)).toEqual(['c1', 'c2']);
    expect(tabs()[0].textContent).toBe('Next4');
});
