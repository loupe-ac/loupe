/** @vitest-environment jsdom */
import { Application, Controller } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import CardMoveController from '../../assets/controllers/card_move_controller.js';
import SheetController from '../../assets/controllers/sheet_controller.js';

vi.mock('../../assets/lib/card_move.js', async (importOriginal) => ({
    ...(await importOriginal()),
    moveCard: vi.fn(),
    moveBusy: vi.fn(() => false),
}));
const { moveBusy, moveCard } = await import('../../assets/lib/card_move.js');

let application;
let dialog;
let reveal;

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const MARKUP = `
<div id="page" data-controller="card-move">
    <div id="board" data-controller="board-columns-nav">
        <form data-board-drag-target="moveForm"></form>
        <section class="lp-board__column" data-column-id="next"><h2 class="lp-board__column-title">Next</h2>
            <div data-board-drag-target="group" data-column="next"><article data-card-id="card-1" data-card-title="Fix the login page">
                <button id="open" data-action="click->card-move#open" data-card-move-id-param="card-1" data-card-move-number-param="991">Move</button>
            </article></div>
        </section>
        <section class="lp-board__column" data-column-id="tech"><h2 class="lp-board__column-title">Tech design</h2></section>
    </div>
    <div data-card-move-target="sheet" data-controller="sheet"
         data-title-format="Move #%number% to" data-question-format="Move it to %column%?" data-done-format="Moved #%number% to %column%"
         data-backlog-id="backlog" data-backlog-label="Backlog">
        <dialog aria-label="Move" data-sheet-target="dialog">
            <button data-sheet-target="back" data-action="click->sheet#back" hidden>Back</button>
            <h2 class="lp-sheet__title"></h2>
            <p data-card-move-target="cardTitle"></p>
            <div data-sheet-view="columns"><div data-card-move-target="rows"></div></div>
            <div data-sheet-view="managed">
                <p data-card-move-target="question"></p>
                <button id="keep" data-action="card-move#keep">Keep</button>
                <button id="unmanage" data-action="card-move#unmanage">Unmanage</button>
            </div>
            <p data-card-move-target="error" hidden>Did not move.</p>
        </dialog>
        <template data-card-move-target="row"><button type="button" class="lp-move-sheet__row" data-action="card-move#choose"><span class="lp-move-sheet__label"></span><span class="lp-move-sheet__current">Current</span><span class="lp-move-sheet__check"></span></button></template>
    </div>
    <div data-card-move-target="flashes"></div>
    <template data-card-move-target="done"><div class="lp-flash"><span data-card-move-message></span><span class="lp-flash__actions"><button data-action="card-move#show">Show</button></span></div></template>
</div>`;

const rows = () => [...document.querySelectorAll('.lp-move-sheet__row')];
const row = (id) =>
    rows().find((candidate) => candidate.dataset.columnId === id);
const flash = () => document.querySelector('.lp-flash');
const view = () =>
    [...dialog.querySelectorAll('[data-sheet-view]')].find((v) => !v.hidden)
        ?.dataset.sheetView;

async function open() {
    document.getElementById('open').click();
    await settle();
}

beforeEach(async () => {
    document.body.innerHTML = MARKUP;
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
        dialog.dispatchEvent(new Event('close'));
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: Promise.resolve(),
        cancel: vi.fn(),
    }));
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    moveCard.mockReset();
    moveBusy.mockReset();
    moveBusy.mockReturnValue(false);
    reveal = vi.fn();
    application = Application.start();
    application.register('card-move', CardMoveController);
    application.register('sheet', SheetController);
    application.register(
        'board-columns-nav',
        class extends Controller {
            reveal(columnId) {
                reveal(columnId);
            }
        },
    );
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.unstubAllGlobals();
});

it('opens with the title of the card and a row for the Backlog and each column', async () => {
    await open();

    expect(dialog.open).toBe(true);
    expect(dialog.querySelector('.lp-sheet__title').textContent).toBe(
        'Move #991 to',
    );
    expect(
        dialog.querySelector('[data-card-move-target="cardTitle"]').textContent,
    ).toBe('Fix the login page');
    expect(rows().map((r) => r.dataset.columnId)).toEqual([
        'backlog',
        'next',
        'tech',
    ]);
    expect(
        rows().map((r) => r.querySelector('.lp-move-sheet__label').textContent),
    ).toEqual(['Backlog', 'Next', 'Tech design']);
});

it('marks the column the card is in, and only that one', async () => {
    await open();

    expect(row('next').getAttribute('aria-current')).toBe('true');
    expect(row('next').querySelector('.lp-move-sheet__current')).not.toBeNull();
    expect(row('tech').hasAttribute('aria-current')).toBe(false);
    expect(row('tech').querySelector('.lp-move-sheet__check')).toBeNull();
});

it('closes with no request when the current column is picked', async () => {
    await open();
    row('next').click();
    await settle();

    expect(moveCard).not.toHaveBeenCalled();
    expect(dialog.close).toHaveBeenCalled();
});

it('moves the card with an empty parent and no rank, then closes and shows the message', async () => {
    moveCard.mockResolvedValue({ status: 'ok' });
    await open();

    row('tech').click();
    await settle();
    expect(moveCard).toHaveBeenCalledOnce();
    const [form, options] = moveCard.mock.calls[0];
    expect(form).toBe(document.querySelector('form'));
    expect(options).toMatchObject({
        cardId: 'card-1',
        columnId: 'tech',
        columnLabel: 'Tech design',
    });
    expect(options.parent).toBeUndefined();
    expect(options.position).toBeUndefined();

    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'card-1' } }),
    );
    await settle();

    expect(dialog.close).toHaveBeenCalled();
    expect(flash().querySelector('[data-card-move-message]').textContent).toBe(
        'Moved #991 to Tech design',
    );
});

it('shows a flash with no Show action for the Backlog', async () => {
    moveCard.mockResolvedValue({ status: 'ok' });
    await open();
    row('backlog').click();
    await settle();
    document.dispatchEvent(
        new CustomEvent('board:place-missed', { detail: { cardId: 'card-1' } }),
    );
    await settle();

    expect(flash().textContent).toContain('Moved #991 to Backlog');
    expect(flash().querySelector('button')).toBeNull();
});

it('opens no sheet while a drag holds the move form', async () => {
    moveBusy.mockReturnValue(true);
    await open();

    expect(moveBusy).toHaveBeenCalledWith(document.querySelector('form'));
    expect(dialog.open).not.toBe(true);
});

it('shows the default text when a drag took the form after the sheet opened', async () => {
    moveCard.mockResolvedValue({ status: 'busy' });
    await open();
    row('tech').click();
    await settle();

    const error = dialog.querySelector('[data-card-move-target="error"]');
    expect(error.hidden).toBe(false);
    expect(error.textContent).toBe('Did not move.');
    expect(row('tech').disabled).toBe(false);
});

it('takes no second row while a move is in flight', async () => {
    moveCard.mockReturnValue(new Promise(() => {}));
    await open();
    row('tech').click();
    await settle();

    expect(row('backlog').disabled).toBe(true);
    row('backlog').click();
    await settle();
    expect(moveCard).toHaveBeenCalledOnce();
});

it('shows the question in the sheet, and moves on after Make unmanaged', async () => {
    let answer;
    moveCard.mockImplementation(async (form, { ask }) => {
        answer = await ask('Make it unmanaged?');

        return { status: 'ok' };
    });
    await open();
    row('tech').click();
    await settle();

    expect(view()).toBe('managed');
    expect(
        dialog.querySelector('[data-card-move-target="question"]').textContent,
    ).toBe('Move it to Tech design?');
    expect(dialog.querySelector('[data-sheet-target="back"]').hidden).toBe(
        true,
    );

    document.getElementById('unmanage').click();
    await settle();
    expect(answer).toBe(true);
    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'card-1' } }),
    );
    await settle();
    expect(flash()).not.toBeNull();
});

it('closes the sheet and moves nothing when Keep it here is picked', async () => {
    moveCard.mockImplementation(async (form, { ask }) =>
        (await ask('Make it unmanaged?'))
            ? { status: 'ok' }
            : { status: 'cancelled', message: 'No.' },
    );
    await open();
    row('tech').click();
    await settle();

    document.getElementById('keep').click();
    await settle();

    expect(dialog.close).toHaveBeenCalled();
    expect(flash()).toBeNull();
});

it('answers no when the sheet is closed with the question open', async () => {
    let answer;
    moveCard.mockImplementation(async (form, { ask }) => {
        answer = await ask('Make it unmanaged?');

        return { status: 'cancelled', message: null };
    });
    await open();
    row('tech').click();
    await settle();

    dialog.close();
    await settle();

    expect(answer).toBe(false);
});

it('shows the default text for a refusal with no message, and the first view again', async () => {
    moveCard.mockResolvedValue({ status: 'refused', message: null });
    await open();
    row('tech').click();
    await settle();

    const error = dialog.querySelector('[data-card-move-target="error"]');
    expect(error.hidden).toBe(false);
    expect(error.textContent).toBe('Did not move.');
    expect(dialog.open).toBe(true);
    expect(row('tech').disabled).toBe(false);
});

it('shows the refusal message of the server when it sends one', async () => {
    moveCard.mockResolvedValue({ status: 'refused', message: 'Not allowed.' });
    await open();
    row('tech').click();
    await settle();

    expect(
        dialog.querySelector('[data-card-move-target="error"]').textContent,
    ).toBe('Not allowed.');
});

it('scrolls the board to the column the flash names', async () => {
    moveCard.mockResolvedValue({ status: 'ok' });
    await open();
    row('tech').click();
    await settle();
    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'card-1' } }),
    );
    await settle();

    flash().querySelector('button').click();

    expect(reveal).toHaveBeenCalledWith('tech');
});
