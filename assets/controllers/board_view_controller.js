import { Controller } from '@hotwired/stimulus';

// A board re-render replaces this element and keeps its parent, so the parent
// keys the chosen view across a stream replace.
const savedViews = new WeakMap();

export default class extends Controller {
    static targets = [
        'board',
        'list',
        'boardButton',
        'listButton',
        'count',
        'card',
    ];
    static values = { listUrl: String };

    connect() {
        this.stateKey = this.element.parentElement;
        this.restore();
        if (
            this.stateKey !== null &&
            savedViews.get(this.stateKey) === 'list'
        ) {
            this.#show('list');
        }
    }

    disconnect() {
        if (this.stateKey !== null) {
            savedViews.set(
                this.stateKey,
                this.boardTarget.hidden ? 'list' : 'board',
            );
        }
    }

    cardTargetConnected() {
        this.#scheduleCount();
    }

    cardTargetDisconnected() {
        this.#scheduleCount();
    }

    /** The count sits in a kept toolbar, so a render never rewrites it. */
    updateCount() {
        if (!this.hasCountTarget) {
            return;
        }
        const total = this.cardTargets.length;
        this.countTarget.textContent =
            total === 1
                ? this.countTarget.dataset.one
                : this.countTarget.dataset.many.replace(
                      '%count%',
                      String(total),
                  );
    }

    /** One pass for all the cards that connect together, such as a whole board. */
    #scheduleCount() {
        if (this.countScheduled) {
            return;
        }
        this.countScheduled = true;
        queueMicrotask(() => {
            this.countScheduled = false;
            this.updateCount();
        });
    }

    /** The mode buttons sit in a kept toolbar, so they remember the view a render resets. */
    restore() {
        const list =
            this.listButtonTarget.getAttribute('aria-pressed') === 'true';
        this.#show(list ? 'list' : 'board');
    }

    showBoard() {
        this.#show('board');
    }

    showList() {
        this.#show('list');
    }

    #show(view) {
        const board = view === 'board';

        this.boardTarget.hidden = !board;
        this.listTarget.hidden = board;
        // The list frame loads only while shown. Turbo rewrites src to an absolute URL, so presence is the test.
        if (board) {
            this.listTarget.removeAttribute('src');
            this.listTarget.replaceChildren();
        } else if (!this.listTarget.hasAttribute('src')) {
            this.listTarget.setAttribute('src', this.listUrlValue);
        }
        for (const button of this.boardButtonTargets) {
            button.setAttribute('aria-pressed', String(board));
        }
        for (const button of this.listButtonTargets) {
            button.setAttribute('aria-pressed', String(!board));
        }
    }
}
