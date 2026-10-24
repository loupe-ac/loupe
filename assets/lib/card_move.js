const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const MANAGED_OFFER_HEADER = 'X-Card-Managed-Offer';
const REFUSAL_HEADER = 'X-Card-Move-Refusal';

/**
 * The form renders under a placeholder card id, in its action, its field
 * names and its field ids. Each move swaps the last id for this card's.
 */
function nameMoveForm(form, cardId) {
    const previous = form.dataset.moveCardId;
    const swap = (value, before, after) =>
        value.replace(
            `${before}${previous}${after}`,
            `${before}${cardId}${after}`,
        );
    form.setAttribute('action', swap(form.getAttribute('action'), '/', '/'));
    for (const field of form.elements) {
        field.name = swap(field.name, '_', '[');
        if (field.id !== '') {
            field.id = swap(field.id, '_', '_');
        }
    }
    form.dataset.moveCardId = cardId;
}

/**
 * Calls back when the page has placed this card, or has failed to. A success
 * is a board-place stream, which renders after `turbo:submit-end`. Returns the
 * function that stops the wait.
 */
export function whenPlaced(cardId, callback) {
    const stop = () => {
        document.removeEventListener('board:placed', placed);
        document.removeEventListener('board:place-missed', placed);
    };
    const placed = (event) => {
        if (event.detail?.cardId !== cardId) {
            return;
        }
        stop();
        callback();
    };
    document.addEventListener('board:placed', placed);
    document.addEventListener('board:place-missed', placed);

    return stop;
}

/**
 * Names the board's one hidden move form after the card, fills it and submits
 * it, which lets Turbo carry the request and the eager CSRF controller stamp
 * the token. A hand-rolled fetch would have to re-implement both.
 *
 * Any answer but a 2xx stream is kept from rendering, so no error page
 * replaces the board. A refusal that asks to make the card unmanaged calls
 * `ask(question)`, which returns a promise of the answer, and a yes sends the
 * move once more with `unmanage` checked. `position` is a rank or an empty
 * string, which appends. An empty `parent` keeps the epic of the card.
 *
 * Resolves with `{status: 'ok'}` once the server accepted the move,
 * `{status: 'refused', message}` when it did not, and
 * `{status: 'cancelled', message}` when the person declined the offer. A
 * message is the refusal text of the server, or null.
 */
export function moveCard(
    form,
    {
        cardId,
        columnId,
        columnLabel = '',
        position = '',
        parent = '',
        beforeCardId = '',
        afterCardId = '',
        ask,
    },
) {
    const column = form.querySelector('select[name$="[column]"]');
    const rank = form.querySelector('input[name$="[position]"]');
    if (column === null || rank === null) {
        return Promise.resolve({ status: 'refused', message: null });
    }
    nameMoveForm(form, cardId);

    // A column added live is missing from a select rendered before it.
    // The server still checks the column against the project.
    if (![...column.options].some((option) => option.value === columnId)) {
        column.add(new Option(columnLabel || columnId, columnId));
    }
    column.value = columnId;
    rank.value = String(position);
    // Written on every move, so a refused move leaves no stale value behind.
    const lane = { parent, beforeCardId, afterCardId };
    for (const name of Object.keys(lane)) {
        const field = form.querySelector(`input[name$="[${name}]"]`);
        if (field !== null) {
            field.value = lane[name];
        }
    }
    const unmanage = form.querySelector('input[name$="[unmanage]"]');
    if (unmanage !== null) {
        unmanage.checked = false;
    }

    return new Promise((resolve) => {
        let refused = false;
        let offer = null;
        let refusal = null;
        const answered = (event) => {
            const response = event.detail.fetchResponse;
            if (
                !response.succeeded ||
                !(response.contentType ?? '').startsWith(STREAM_TYPE)
            ) {
                refused = true;
                offer = response.header?.(MANAGED_OFFER_HEADER) ?? null;
                refusal = response.header?.(REFUSAL_HEADER) ?? null;
                event.preventDefault();
            }
        };
        const finished = (event) => {
            form.removeEventListener('turbo:before-fetch-response', answered);
            form.removeEventListener('turbo:submit-end', finished);
            if (event.detail.success && !refused) {
                resolve({ status: 'ok' });

                return;
            }
            if (offer !== null && unmanage !== null && !unmanage.checked) {
                // After Turbo ends this submission, which it does once this event returns.
                const question = offer;
                const answer = refusal;
                setTimeout(() => {
                    Promise.resolve(ask(question)).then((yes) => {
                        if (yes) {
                            unmanage.checked = true;
                            send();

                            return;
                        }
                        resolve({ status: 'cancelled', message: answer });
                    });
                });

                return;
            }
            resolve({ status: 'refused', message: refusal });
        };
        const send = () => {
            refused = false;
            offer = null;
            refusal = null;
            form.addEventListener('turbo:before-fetch-response', answered);
            form.addEventListener('turbo:submit-end', finished);
            form.requestSubmit();
        };

        send();
    });
}
