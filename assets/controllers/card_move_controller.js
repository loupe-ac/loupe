import { Controller } from '@hotwired/stimulus';
import { moveCard, whenPlaced } from '../lib/card_move.js';

/** How long the sheet waits for the board to place the card before it closes anyway. */
const PLACEMENT_WAIT = 3000;

const SHEET_WAIT_STEP = 50;
const SHEET_WAIT_STEPS = 40;

/**
 * The Move sheet of the board. The "…" button of a card face opens it, and a
 * row moves the card to that column through the same helper a drag uses, with
 * an empty `parent` so the card keeps its epic. The element holds the board
 * and the sheet, and the sheet sits outside `#board`, so no board stream
 * replaces it while it is open.
 */
export default class extends Controller {
    static targets = [
        'sheet',
        'rows',
        'row',
        'question',
        'error',
        'done',
        'flashes',
    ];

    // A field, because Stimulus reports a target before it calls connect().
    onClosed = () => this.#answer(false);

    connect() {
        this.cardId = null;
        this.cardNumber = '';
        this.currentColumnId = '';
        this.pending = false;
        this.answer = null;
    }

    sheetTargetConnected(element) {
        element
            .querySelector('dialog')
            ?.addEventListener('close', this.onClosed);
    }

    sheetTargetDisconnected(element) {
        element
            .querySelector('dialog')
            ?.removeEventListener('close', this.onClosed);
    }

    errorTargetConnected(element) {
        element.dataset.defaultMessage = element.textContent;
    }

    async open(event) {
        if (this.pending) {
            return;
        }
        const { id, number } = event.params;
        const group = event.currentTarget.closest(
            '[data-board-drag-target="group"]',
        );
        const sheet = await this.#sheet();
        if (sheet === null) {
            return;
        }
        this.cardId = String(id);
        this.cardNumber = String(number);
        this.currentColumnId = group?.dataset.column ?? '';
        this.#fill();
        sheet.open();
    }

    async choose(event) {
        if (this.pending) {
            return;
        }
        const row = event.currentTarget;
        const sheet = await this.#sheet();
        const columnId = row.dataset.columnId;
        if (columnId === this.currentColumnId) {
            sheet.close();

            return;
        }
        const form = this.element.querySelector(
            'form[data-board-drag-target="moveForm"]',
        );
        if (form === null) {
            this.#fail(null);

            return;
        }

        const cardId = this.cardId;
        const cardNumber = this.cardNumber;
        const label = row.querySelector('.lp-move-sheet__label').textContent;
        this.errorTarget.hidden = true;
        this.#setPending(true);
        const result = await moveCard(form, {
            cardId,
            columnId,
            columnLabel: label,
            ask: (question) => this.#ask(question),
        });

        if ('ok' === result.status) {
            await this.#placed(cardId);
            this.#setPending(false);
            sheet.close();
            this.#announce(cardNumber, columnId, label);

            return;
        }
        this.#setPending(false);
        if ('cancelled' === result.status) {
            sheet.close();
        } else {
            this.#fail(result.message);
        }
    }

    keep() {
        this.#answer(false);
    }

    unmanage() {
        this.#answer(true);
    }

    /** Scrolls the board to the column the card moved to. */
    show(event) {
        const columnId =
            event.currentTarget.closest('.lp-flash')?.dataset.columnId;
        const board = this.element.querySelector('#board');
        if (columnId === undefined || board === null) {
            return;
        }
        this.application
            .getControllerForElementAndIdentifier(board, 'board-columns-nav')
            ?.reveal(columnId);
    }

    /** The sheet controller is lazy, so a tap that comes first waits for it to load. */
    async #sheet() {
        for (let step = 0; step < SHEET_WAIT_STEPS; step++) {
            const sheet = this.application.getControllerForElementAndIdentifier(
                this.sheetTarget,
                'sheet',
            );
            if (sheet) {
                return sheet;
            }
            await new Promise((resolve) =>
                setTimeout(resolve, SHEET_WAIT_STEP),
            );
        }

        return null;
    }

    /** The Backlog first, then each column of the board in the order it shows them. */
    #columns() {
        const columns = new Map([
            [
                this.sheetTarget.dataset.backlogId,
                this.sheetTarget.dataset.backlogLabel,
            ],
        ]);
        for (const column of this.element.querySelectorAll(
            '#board .lp-board__column[data-column-id]',
        )) {
            const id = column.dataset.columnId;
            if (!columns.has(id)) {
                columns.set(
                    id,
                    column
                        .querySelector('.lp-board__column-title')
                        ?.textContent.trim() ?? '',
                );
            }
        }

        return columns;
    }

    #fill() {
        const title = this.sheetTarget.dataset.titleFormat.replace(
            '%number%',
            () => this.cardNumber,
        );
        const dialog = this.sheetTarget.querySelector('dialog');
        dialog.querySelector('.lp-sheet__title').textContent = title;
        dialog.setAttribute('aria-label', title);

        const rows = [];
        for (const [id, label] of this.#columns()) {
            const row =
                this.rowTarget.content.firstElementChild.cloneNode(true);
            const current = id === this.currentColumnId;
            row.dataset.columnId = id;
            row.querySelector('.lp-move-sheet__label').textContent = label;
            if (current) {
                row.setAttribute('aria-current', 'true');
            } else {
                row.querySelector('.lp-move-sheet__current').remove();
                row.querySelector('.lp-move-sheet__check').remove();
            }
            rows.push(row);
        }
        this.rowsTarget.replaceChildren(...rows);
        this.errorTarget.hidden = true;
    }

    #setPending(pending) {
        this.pending = pending;
        this.rowsTarget.toggleAttribute('aria-busy', pending);
        for (const row of this.rowsTarget.querySelectorAll('button')) {
            row.disabled = pending;
        }
    }

    /** Shows the managed-card question in the sheet, and resolves with the answer. */
    #ask(question) {
        return new Promise((resolve) => {
            this.answer = resolve;
            this.questionTarget.textContent = question;
            const sheet = this.application.getControllerForElementAndIdentifier(
                this.sheetTarget,
                'sheet',
            );
            sheet.showView('managed');
            // The two buttons are the way out of the question, so no back arrow leaves it open.
            if (sheet.hasBackTarget) {
                sheet.backTarget.hidden = true;
            }
        });
    }

    #answer(value) {
        const resolve = this.answer;
        this.answer = null;
        resolve?.(value);
    }

    #fail(message) {
        const sheet = this.application.getControllerForElementAndIdentifier(
            this.sheetTarget,
            'sheet',
        );
        if (sheet.trail.length > 0) {
            sheet.back();
        }
        this.errorTarget.textContent =
            message ?? this.errorTarget.dataset.defaultMessage;
        this.errorTarget.hidden = false;
    }

    /** Resolves once the board has placed the card, or after a short wait when it never says. */
    #placed(cardId) {
        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                stop();
                resolve();
            }, PLACEMENT_WAIT);
            const stop = whenPlaced(cardId, () => {
                clearTimeout(timer);
                resolve();
            });
        });
    }

    #announce(cardNumber, columnId, label) {
        const flash = this.doneTarget.content.firstElementChild.cloneNode(true);
        flash.dataset.columnId = columnId;
        flash.querySelector('[data-card-move-message]').textContent =
            this.sheetTarget.dataset.doneFormat
                .replace('%number%', () => cardNumber)
                .replace('%column%', () => label);
        // The Backlog is a page of its own, so there is no column to scroll to.
        if (columnId === this.sheetTarget.dataset.backlogId) {
            flash.querySelector('.lp-flash__actions')?.remove();
        }
        this.flashesTarget.replaceChildren(flash);
    }
}
