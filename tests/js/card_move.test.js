/** @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { moveCard, whenPlaced } from '../../assets/lib/card_move.js';

const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';
const PROTOTYPE = '00000000-0000-4000-8000-000000000000';
const OFFER = 'This card is managed. Make it unmanaged and move it?';
const REFUSAL = 'This card is managed. Make it unmanaged to move it there.';

let form;

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    document.body.innerHTML = `<form hidden action="/cards/${PROTOTYPE}/move" data-move-card-id="${PROTOTYPE}">
        <select id="move_card_${PROTOTYPE}_column" name="move_card_${PROTOTYPE}[column]"><option value="backlog">Backlog</option><option value="next">Next</option></select>
        <input id="move_card_${PROTOTYPE}_position" name="move_card_${PROTOTYPE}[position]">
        <input name="move_card_${PROTOTYPE}[parent]" value="stale">
        <input name="move_card_${PROTOTYPE}[beforeCardId]" value="stale">
        <input name="move_card_${PROTOTYPE}[afterCardId]" value="stale">
        <input type="checkbox" value="1" name="move_card_${PROTOTYPE}[unmanage]">
    </form>`;
    form = document.querySelector('form');
    form.requestSubmit = vi.fn();
});

afterEach(() => {
    vi.restoreAllMocks();
});

function respond(fetchResponse) {
    const event = new CustomEvent('turbo:before-fetch-response', {
        bubbles: true,
        cancelable: true,
        detail: { fetchResponse },
    });
    form.dispatchEvent(event);

    return event.defaultPrevented;
}

function finish(success) {
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { success },
        }),
    );
}

const managed = {
    succeeded: false,
    contentType: 'text/html',
    header: (name) =>
        ({
            'X-Card-Managed-Offer': OFFER,
            'X-Card-Move-Refusal': REFUSAL,
        })[name] ?? null,
};

const field = (name) => form.querySelector(`[name$="[${name}]"]`);

it('names the form after the card, fills it and submits it', () => {
    moveCard(form, {
        cardId: 'a',
        columnId: 'next',
        position: 2,
        ask: vi.fn(),
    });

    expect(form.getAttribute('action')).toBe('/cards/a/move');
    expect(field('column').name).toBe('move_card_a[column]');
    expect(field('column').id).toBe('move_card_a_column');
    expect(field('column').value).toBe('next');
    expect(field('position').value).toBe('2');
    expect(form.requestSubmit).toHaveBeenCalledOnce();
});

it('writes an empty parent and no neighbour by default, which keeps the epic and appends', () => {
    moveCard(form, { cardId: 'a', columnId: 'next', ask: vi.fn() });

    expect(field('position').value).toBe('');
    expect(field('parent').value).toBe('');
    expect(field('beforeCardId').value).toBe('');
    expect(field('afterCardId').value).toBe('');
});

it('sends the lane fields it is given', () => {
    moveCard(form, {
        cardId: 'a',
        columnId: 'next',
        parent: 'epic',
        beforeCardId: 'b',
        ask: vi.fn(),
    });

    expect(field('parent').value).toBe('epic');
    expect(field('beforeCardId').value).toBe('b');
});

it('adds the option of a column that appeared after the page loaded', () => {
    moveCard(form, {
        cardId: 'a',
        columnId: 'review',
        columnLabel: 'Review',
        ask: vi.fn(),
    });

    expect(field('column').value).toBe('review');
    expect(field('column').selectedOptions[0].textContent).toBe('Review');
});

it('resolves ok when the server answers with a stream', async () => {
    const result = moveCard(form, {
        cardId: 'a',
        columnId: 'next',
        ask: vi.fn(),
    });

    expect(respond({ succeeded: true, contentType: STREAM })).toBe(false);
    finish(true);

    await expect(result).resolves.toEqual({ status: 'ok' });
});

it('resolves refused and keeps the answer from rendering', async () => {
    const result = moveCard(form, {
        cardId: 'a',
        columnId: 'next',
        ask: vi.fn(),
    });

    expect(respond({ succeeded: false, contentType: 'text/html' })).toBe(true);
    finish(false);

    await expect(result).resolves.toEqual({ status: 'refused', message: null });
});

it('treats a success that is not a stream as a refusal', async () => {
    const result = moveCard(form, {
        cardId: 'a',
        columnId: 'next',
        ask: vi.fn(),
    });

    expect(respond({ succeeded: true, contentType: 'text/html' })).toBe(true);
    finish(true);

    await expect(result).resolves.toEqual({ status: 'refused', message: null });
});

it('asks the offered question, then sends the move again with unmanage on yes', async () => {
    const ask = vi.fn().mockResolvedValue(true);
    const result = moveCard(form, { cardId: 'a', columnId: 'next', ask });

    respond(managed);
    finish(false);
    expect(ask).not.toHaveBeenCalled();
    await settle();

    expect(ask).toHaveBeenCalledWith(OFFER);
    expect(field('unmanage').checked).toBe(true);
    expect(form.requestSubmit).toHaveBeenCalledTimes(2);

    expect(respond({ succeeded: true, contentType: STREAM })).toBe(false);
    finish(true);
    await expect(result).resolves.toEqual({ status: 'ok' });
});

it('resolves cancelled with the refusal text on no', async () => {
    const result = moveCard(form, {
        cardId: 'a',
        columnId: 'next',
        ask: vi.fn().mockResolvedValue(false),
    });

    respond(managed);
    finish(false);

    await expect(result).resolves.toEqual({
        status: 'cancelled',
        message: REFUSAL,
    });
    expect(form.requestSubmit).toHaveBeenCalledOnce();
});

it('asks once, and resolves refused when the second answer is refused too', async () => {
    const ask = vi.fn().mockResolvedValue(true);
    const result = moveCard(form, { cardId: 'a', columnId: 'next', ask });

    respond(managed);
    finish(false);
    await settle();
    respond(managed);
    finish(false);

    await expect(result).resolves.toMatchObject({ status: 'refused' });
    expect(ask).toHaveBeenCalledOnce();
});

it('asks nothing when the refusal carries no question', async () => {
    const ask = vi.fn();
    const result = moveCard(form, { cardId: 'a', columnId: 'next', ask });

    respond({ ...managed, header: () => null });
    finish(false);

    await expect(result).resolves.toEqual({ status: 'refused', message: null });
    expect(ask).not.toHaveBeenCalled();
});

it('clears unmanage on the next move', async () => {
    const ask = vi.fn().mockResolvedValue(true);
    moveCard(form, { cardId: 'a', columnId: 'next', ask });
    respond(managed);
    finish(false);
    await settle();
    expect(field('unmanage').checked).toBe(true);

    moveCard(form, { cardId: 'b', columnId: 'next', ask });

    expect(field('unmanage').checked).toBe(false);
});

it('refuses at once when the form has no column or position field', async () => {
    form.querySelector('select').remove();

    await expect(
        moveCard(form, { cardId: 'a', columnId: 'next', ask: vi.fn() }),
    ).resolves.toEqual({ status: 'refused', message: null });
    expect(form.requestSubmit).not.toHaveBeenCalled();
});

it('calls back once the page places the card it names, and stops listening', () => {
    const callback = vi.fn();
    whenPlaced('a', callback);

    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'other' } }),
    );
    expect(callback).not.toHaveBeenCalled();

    document.dispatchEvent(
        new CustomEvent('board:place-missed', { detail: { cardId: 'a' } }),
    );
    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'a' } }),
    );
    expect(callback).toHaveBeenCalledOnce();
});

it('stops waiting for a placement when told to', () => {
    const callback = vi.fn();
    whenPlaced('a', callback)();

    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'a' } }),
    );

    expect(callback).not.toHaveBeenCalled();
});
