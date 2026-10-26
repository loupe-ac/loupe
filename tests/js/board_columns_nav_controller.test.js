/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardColumnsNavController from '../../assets/controllers/board_columns_nav_controller.js';

let application;
let scrollTo;

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

const strip = () => document.querySelector('.lp-board__columns');

function scrolledTo(left) {
    strip().scrollLeft = left;
    strip().dispatchEvent(new Event('scrollend'));
}

function touch(startX, startY, endX, endY) {
    const point = (clientX, clientY) => ({ clientX, clientY });
    const fire = (type, touches, changed) => {
        const event = new Event(type, { bubbles: true });
        event.touches = touches;
        event.changedTouches = changed;
        strip().dispatchEvent(event);
    };
    fire('touchstart', [point(startX, startY)], [point(startX, startY)]);
    fire('touchend', [], [point(endX, endY)]);
}

const step = (direction) =>
    document.getElementById('board').dispatchEvent(
        new CustomEvent('overflow-cue:step', {
            bubbles: true,
            detail: { direction },
        }),
    );

beforeEach(() => {
    vi.useFakeTimers();
    scrollTo = vi.fn();
    Element.prototype.scrollTo = scrollTo;
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(
        function () {
            const id = this.dataset?.columnId;
            const left = id ? 5 + (Number(id.slice(1)) - 1) * 100 : 0;

            return { left, right: left + 100, top: 0, bottom: 0 };
        },
    );
    vi.stubGlobal('innerWidth', 390);
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    vi.stubGlobal('CSS', { escape: (value) => value });
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete Element.prototype.scrollTo;
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

it('follows the strip: the column nearest the scroll position becomes the current tab', async () => {
    await start();
    scrolledTo(100);
    expect(selected()).toBe('c2');
    expect(prev().hidden).toBe(false);
    expect(next().hidden).toBe(false);
    scrolledTo(190);
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
});

it('steps one column to the right on a step event, moving sideways only', async () => {
    await start();
    step(1);
    expect(scrollTo).toHaveBeenCalledTimes(1);
    expect(scrollTo.mock.contexts[0]).toBe(strip());
    expect(scrollTo.mock.calls[0][0]).toMatchObject({ left: 100 });
    expect('top' in scrollTo.mock.calls[0][0]).toBe(false);
    expect(selected()).toBe('c2');
    expect(prev().hidden).toBe(false);
});

it('keeps the vertical position when a tab or an arrow moves the board', async () => {
    await start();
    strip().scrollTop = 320;
    step(1);
    tabs()[2].click();
    for (const [options] of scrollTo.mock.calls) {
        expect('top' in options).toBe(false);
    }
    expect(strip().scrollTop).toBe(320);
});

it('steps from the column it is heading to, not the one it left', async () => {
    await start();
    step(1);
    step(1);
    expect(scrollTo.mock.calls[1][0]).toMatchObject({ left: 200 });
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
});

it('never steps past either end', async () => {
    await start();
    step(-1);
    expect(selected()).toBe('c1');
    expect(prev().hidden).toBe(true);
    for (let index = 0; index < 5; index++) {
        step(1);
    }
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
});

it('scrolls to the column of a tapped tab', async () => {
    await start();
    tabs()[2].click();
    expect(scrollTo.mock.calls[0][0]).toMatchObject({ left: 200 });
    expect(selected()).toBe('c3');
    expect(next().hidden).toBe(true);
    expect(prev().hidden).toBe(false);
});

it('settles on the scroll position when the scroll ends', async () => {
    await start();
    tabs()[2].click();
    scrolledTo(200);
    expect(selected()).toBe('c3');
    vi.advanceTimersByTime(800);
    expect(selected()).toBe('c3');
});

it('settles on the scroll position when no scrollend arrives', async () => {
    await start();
    tabs()[1].click();
    strip().scrollLeft = 100;
    vi.advanceTimersByTime(800);
    expect(selected()).toBe('c2');
});

it('steps one column on a clear sideways swipe, and back on the opposite one', async () => {
    await start();
    touch(300, 400, 200, 410);
    expect(scrollTo.mock.calls[0][0]).toMatchObject({ left: 100 });
    expect(selected()).toBe('c2');
    touch(100, 400, 220, 390);
    expect(scrollTo.mock.calls[1][0]).toMatchObject({ left: 0 });
    expect(selected()).toBe('c1');
});

it('leaves the column alone on a mostly vertical touch', async () => {
    await start();
    touch(300, 500, 230, 380);
    touch(300, 500, 100, 380);
    touch(300, 500, 270, 300);
    expect(scrollTo).not.toHaveBeenCalled();
    expect(selected()).toBe('c1');
});

it('ignores a touch that is a short sideways move', async () => {
    await start();
    touch(300, 400, 260, 400);
    expect(scrollTo).not.toHaveBeenCalled();
});

it('ignores a swipe on a wide screen', async () => {
    await start();
    vi.stubGlobal('innerWidth', 1024);
    touch(300, 400, 100, 400);
    expect(scrollTo).not.toHaveBeenCalled();
});

it('ignores a swipe that a second finger joined', async () => {
    await start();
    const event = new Event('touchstart', { bubbles: true });
    event.touches = [
        { clientX: 300, clientY: 400 },
        { clientX: 320, clientY: 400 },
    ];
    event.changedTouches = [event.touches[0]];
    strip().dispatchEvent(event);
    const end = new Event('touchend', { bubbles: true });
    end.touches = [];
    end.changedTouches = [{ clientX: 100, clientY: 400 }];
    strip().dispatchEvent(end);
    expect(scrollTo).not.toHaveBeenCalled();
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

async function startLanes(laneClasses) {
    const lane = (name, classes) => `
        <section class="lp-board-lane ${classes}" data-lane="${name}"><div class="lp-board-lane__cells">
            ${COLUMNS.map(column).join('')}
        </div></section>`;
    document.body.innerHTML = `
        <div id="board" data-controller="board-columns-nav">
            <div data-board-columns-nav-target="tabs">
                <div class="lp-tabs"></div>
                <button class="lp-overflow-cue__arrow--prev lp-overflow-cue__arrow"></button>
                <button class="lp-overflow-cue__arrow--next lp-overflow-cue__arrow"></button>
            </div>
            <div class="lp-board__columns lp-board__columns--lanes" data-board-view-target="board">
                <div class="lp-board-lanes">
                    ${lane('epic', laneClasses[0])}${lane('other', laneClasses[1])}
                </div>
            </div>
        </div>`;
    application = Application.start();
    application.register('board-columns-nav', BoardColumnsNavController);
    await vi.advanceTimersByTimeAsync(0);
}

it('moves a tab to the same column offset when every lane is collapsed', async () => {
    await startLanes(['lp-board-lane--collapsed', 'lp-board-lane--collapsed']);
    tabs()[2].click();
    expect(scrollTo.mock.calls[0][0]).toMatchObject({ left: 200 });
    step(-1);
    expect(scrollTo.mock.calls[1][0]).toMatchObject({ left: 100 });
    scrolledTo(100);
    expect(selected()).toBe('c2');
});

it('reads the same offsets whether the first lane is collapsed or open', async () => {
    await startLanes(['lp-board-lane--collapsed', '']);
    tabs()[1].click();
    expect(scrollTo.mock.calls[0][0]).toMatchObject({ left: 100 });
});

it('reveals the column with an id, and ignores an id it has no column for', async () => {
    await start();
    const nav = application.getControllerForElementAndIdentifier(
        document.getElementById('board'),
        'board-columns-nav',
    );

    nav.reveal('missing');
    expect(scrollTo).not.toHaveBeenCalled();

    nav.reveal('c3');
    expect(scrollTo.mock.calls[0][0]).toMatchObject({ left: 200 });
    expect(selected()).toBe('c3');
});

it('keeps the column on screen when a live layout switch replaces the strip', async () => {
    await start();
    tabs()[1].click();
    scrolledTo(100);
    expect(selected()).toBe('c2');

    strip().outerHTML = `
        <div class="lp-board__columns lp-board__columns--lanes" data-board-view-target="board">
            <div class="lp-board-lanes">
                <section class="lp-board-lane"><div class="lp-board-lane__cells">
                    ${COLUMNS.map(column).join('')}
                </div></section>
            </div>
        </div>`;
    document.dispatchEvent(new CustomEvent('board:structure-changed'));

    expect(strip().scrollLeft).toBe(100);
    expect(selected()).toBe('c2');
    expect(prev().hidden).toBe(false);
});

it('selects the column at the scroll position when a live switch drops the column on screen', async () => {
    await start();
    tabs()[2].click();
    scrolledTo(200);

    strip().outerHTML = `
        <div class="lp-board__columns" data-board-view-target="board">
            ${COLUMNS.slice(0, 2).map(column).join('')}
        </div>`;
    document.dispatchEvent(new CustomEvent('board:structure-changed'));

    expect(selected()).toBe('c1');
    expect(prev().hidden).toBe(true);
});
