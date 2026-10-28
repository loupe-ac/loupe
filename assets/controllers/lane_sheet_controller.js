import { Controller } from '@hotwired/stimulus';

const SHEET_WAIT_STEP = 50;
const SHEET_WAIT_STEPS = 40;

/**
 * The lane sheet of the phone board, one for the page. The "…" button of an
 * epic lane head opens it, and it holds the actions the phone head leaves out.
 * The button carries the server data. The collapse of a lane lives in the
 * browser, so the sheet reads the lane at each open and acts on the lane it
 * finds by epic id, which a morph of the lane element cannot stale.
 */
export default class extends Controller {
    static targets = [
        'sheet',
        'cardsLabel',
        'moveRow',
        'moveLabel',
        'upNextRow',
    ];
    static outlets = ['board-lane'];

    open = async (event) => {
        const { epic, number, column, moveLabel, upNextLabel, upNextUrl } =
            event.params;
        const head = event.currentTarget.closest('[data-card-title]');
        const title = head?.dataset.cardTitle ?? '';
        const sheet = await this.#sheet();
        if (sheet === null) {
            return;
        }
        this.epicId = String(epic);
        const heading = `#${number} ${title}`;
        const dialog = this.sheetTarget.querySelector('dialog');
        dialog.setAttribute('aria-label', heading);
        this.#fillTitle(dialog, heading, head);
        this.#fillCards();
        this.#fillMove({ epic, number, column, moveLabel, title });
        this.#fillUpNext(upNextLabel, upNextUrl);
        sheet.open();
    };

    toggleCards() {
        this.#lane()?.toggle();
        this.dismiss();
    }

    hideLane() {
        this.#lane()
            ?.element.querySelector('[data-board-lane-form]')
            ?.requestSubmit();
        this.dismiss();
    }

    dismiss() {
        this.application
            .getControllerForElementAndIdentifier(this.sheetTarget, 'sheet')
            ?.close();
    }

    /** The lane of the epic that opened the sheet, or undefined when none is on the page. */
    #lane() {
        if (!this.hasBoardLaneOutlet) {
            return undefined;
        }

        return this.boardLaneOutlets.find(
            (lane) => lane.epicValue === this.epicId,
        );
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

    /** The state mark is a copy, so its tooltip ids get a suffix and stay unique on the page. */
    #fillTitle(dialog, heading, head) {
        const text = document.createElement('span');
        text.textContent = heading;
        const parts = [text];
        const mark = head?.querySelector('.lp-state-mark');
        if (mark) {
            const copy = mark.cloneNode(true);
            for (const element of copy.querySelectorAll('[id]')) {
                element.id = `${element.id}-sheet`;
            }
            for (const element of copy.querySelectorAll('[aria-describedby]')) {
                element.setAttribute(
                    'aria-describedby',
                    `${element.getAttribute('aria-describedby')}-sheet`,
                );
            }
            parts.unshift(copy);
        }
        dialog.querySelector('.lp-sheet__title').replaceChildren(...parts);
    }

    #fillCards() {
        const lane = this.#lane();
        const row = this.cardsLabelTarget.closest('button');
        row.hidden = lane === undefined;
        if (lane === undefined) {
            return;
        }
        const collapsed = lane.element.classList.contains(
            'lp-board-lane--collapsed',
        );
        this.cardsLabelTarget.textContent = collapsed
            ? this.sheetTarget.dataset.showLabel
            : this.sheetTarget.dataset.hideLabel;
    }

    /** The Move row hands its epic to the card-move controller through its params. */
    #fillMove({ epic, number, column, moveLabel, title }) {
        const row = this.moveRowTarget;
        row.dataset.cardMoveIdParam = String(epic);
        row.dataset.cardMoveNumberParam = String(number);
        row.dataset.cardMoveColumnParam = String(column);
        row.dataset.cardTitle = title;
        this.moveLabelTarget.textContent = moveLabel;
    }

    #fillUpNext(label, url) {
        const row = this.upNextRowTarget;
        row.hidden = !url;
        if (url) {
            row.textContent = label;
            row.setAttribute('href', url);
        }
    }
}
