/** @vitest-environment jsdom */
import { Application, Controller } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardLaneController from '../../assets/controllers/board_lane_controller.js';
import LaneSheetController from '../../assets/controllers/lane_sheet_controller.js';
import SheetController from '../../assets/controllers/sheet_controller.js';

const KEY = 'loupe.board.collapsed-lanes.project-1';

let application;
let dialog;
let moveOpened;

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function laneMarkup(epic, number, title, withDeck) {
    return `<section class="lp-board-lane" id="board-lane-${epic}" data-controller="board-lane"
            data-board-lane-project-value="project-1" data-board-lane-epic-value="${epic}">
        <header class="lp-board-lane__head" data-card-title="${title}">
            <button type="button" data-board-lane-target="toggle" data-action="board-lane#toggle">Chevron</button>
            <form data-board-lane-form action="/lane/${epic}"><button type="submit">Hide</button></form>
            <span data-lane-status>
                <span class="lp-state-mark lp-state-mark--stuck"><span id="tip-${epic}" role="tooltip">Stuck</span><span aria-describedby="tip-${epic}" role="img" aria-label="Stuck"></span></span>
            </span>
            <button type="button" id="menu-${epic}" class="lp-board-lane__menu"
                    data-action="click->lane-sheet#open"
                    data-lane-sheet-epic-param="${epic}"
                    data-lane-sheet-number-param="${number}"
                    data-lane-sheet-column-param="column-${epic}"
                    data-lane-sheet-move-label-param="Move #${number}"
                    data-lane-sheet-up-next-label-param="${withDeck ? 'Up next: 3 cards' : ''}"
                    data-lane-sheet-up-next-url-param="${withDeck ? `/backlog?epic=${epic}` : ''}">Menu</button>
        </header>
    </section>`;
}

const MARKUP = `
<div id="page" data-controller="lane-sheet card-move"
     data-lane-sheet-board-lane-outlet="[data-controller~='board-lane']">
    <div id="board">
        ${laneMarkup('epic-a', 12, 'Phone lanes', true)}
        ${laneMarkup('epic-b', 13, 'Plain lane', false)}
    </div>
    <div data-lane-sheet-target="sheet" data-controller="sheet" data-show-label="Show cards" data-hide-label="Hide cards">
        <dialog aria-label="Lane" data-sheet-target="dialog">
            <h2 class="lp-sheet__title">Lane</h2>
            <button id="cards" type="button" data-action="lane-sheet#toggleCards"><span data-lane-sheet-target="cardsLabel"></span></button>
            <button id="move" type="button" data-lane-sheet-target="moveRow" data-action="click->card-move#open click->lane-sheet#dismiss"><span data-lane-sheet-target="moveLabel"></span></button>
            <button id="hide" type="button" data-action="lane-sheet#hideLane">Hide lane</button>
            <a id="up-next" data-lane-sheet-target="upNextRow" href="/" data-action="click->lane-sheet#dismiss" hidden></a>
        </dialog>
    </div>
</div>`;

const lane = (epic) => document.getElementById(`board-lane-${epic}`);
const label = () =>
    document.querySelector('[data-lane-sheet-target="cardsLabel"]').textContent;

async function openFor(epic) {
    document.getElementById(`menu-${epic}`).click();
    await settle();
}

beforeEach(async () => {
    window.localStorage.clear();
    moveOpened = vi.fn();
    document.body.innerHTML = MARKUP;
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: Promise.resolve(),
        cancel: vi.fn(),
    }));
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    application = Application.start();
    application.register('board-lane', BoardLaneController);
    application.register('lane-sheet', LaneSheetController);
    application.register('sheet', SheetController);
    application.register(
        'card-move',
        class extends Controller {
            open(event) {
                moveOpened({
                    params: { ...event.params },
                    title: event.currentTarget.dataset.cardTitle,
                });
            }
        },
    );
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    window.localStorage.clear();
    vi.unstubAllGlobals();
});

it('opens with the epic number and title', async () => {
    await openFor('epic-a');

    expect(dialog.open).toBe(true);
    expect(dialog.querySelector('.lp-sheet__title').textContent).toContain(
        '#12 Phone lanes',
    );
    expect(dialog.getAttribute('aria-label')).toBe('#12 Phone lanes');
});

it('offers Hide cards for an open lane', async () => {
    await openFor('epic-a');

    expect(label()).toBe('Hide cards');
});

it('offers Show cards for a lane collapsed from localStorage', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    document.body.innerHTML = MARKUP;
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: Promise.resolve(),
        cancel: vi.fn(),
    }));
    await settle();
    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );

    await openFor('epic-a');

    expect(label()).toBe('Show cards');
});

it('reads the lane again at each open', async () => {
    await openFor('epic-a');
    expect(label()).toBe('Hide cards');

    lane('epic-a').classList.add('lp-board-lane--collapsed');
    await openFor('epic-a');

    expect(label()).toBe('Show cards');
});

it('toggles the lane of the epic and closes the sheet', async () => {
    await openFor('epic-b');

    document.getElementById('cards').click();
    await settle();

    expect(lane('epic-b').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );
    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );
    expect(JSON.parse(window.localStorage.getItem(KEY))).toEqual(['epic-b']);
    expect(dialog.close).toHaveBeenCalled();
});

it('finds the lane again after a morph replaced its element', async () => {
    const old = lane('epic-a');
    const fresh = document.createElement('div');
    fresh.innerHTML = laneMarkup('epic-a', 12, 'Phone lanes', true);
    old.replaceWith(fresh.firstElementChild);
    await settle();

    await openFor('epic-a');
    document.getElementById('cards').click();
    await settle();

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );
});

it('opens the Move sheet for the epic and closes itself', async () => {
    await openFor('epic-a');

    document.getElementById('move').click();
    await settle();

    expect(document.getElementById('move').textContent).toBe('Move #12');
    expect(moveOpened).toHaveBeenCalledWith({
        params: { id: 'epic-a', number: 12, column: 'column-epic-a' },
        title: 'Phone lanes',
    });
    expect(dialog.close).toHaveBeenCalled();
});

it('submits the hide-lane form of the epic and closes the sheet', async () => {
    const submitted = [];
    for (const epic of ['epic-a', 'epic-b']) {
        lane(epic).querySelector('form').requestSubmit = () =>
            submitted.push(epic);
    }
    await openFor('epic-b');

    document.getElementById('hide').click();
    await settle();

    expect(submitted).toEqual(['epic-b']);
    expect(dialog.close).toHaveBeenCalled();
});

it('shows an Up next link only for an epic with a deck', async () => {
    await openFor('epic-a');
    const row = document.getElementById('up-next');
    expect(row.hidden).toBe(false);
    expect(row.textContent).toBe('Up next: 3 cards');
    expect(row.getAttribute('href')).toBe('/backlog?epic=epic-a');

    await openFor('epic-b');
    expect(row.hidden).toBe(true);
});

it('copies the state mark of the head into the title with fresh ids', async () => {
    await openFor('epic-a');

    const mark = dialog.querySelector('.lp-sheet__title .lp-state-mark');
    expect(mark).not.toBeNull();
    const tooltip = mark.querySelector('[role="tooltip"]');
    expect(tooltip.id).not.toBe('tip-epic-a');
    expect(
        mark
            .querySelector('[aria-describedby]')
            .getAttribute('aria-describedby'),
    ).toBe(tooltip.id);
    expect(document.querySelectorAll('#tip-epic-a')).toHaveLength(1);
});

it('shows no state mark for an epic that has none', async () => {
    await openFor('epic-a');
    lane('epic-a').querySelector('[data-lane-status]').replaceChildren();

    await openFor('epic-a');

    expect(dialog.querySelector('.lp-sheet__title .lp-state-mark')).toBeNull();
});
