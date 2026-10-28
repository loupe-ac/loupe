import ModalController from './modal_controller.js';
import { emit, on } from '../lib/live.js';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';
import { LazyFrame } from '../lib/lazy_frame.js';

const STREAM_TYPE = 'text/vnd.turbo-stream.html';

/* stimulusFetch: 'eager' */
export default class extends ModalController {
    static targets = [
        'dialog',
        'frame',
        'loading',
        'title',
        'error',
        'deleted',
        'clash',
    ];
    static values = {
        reopen: Boolean,
        drawer: { type: Boolean, default: true },
        // The board, or the list behind it, stays usable while a card is open.
        modeless: { type: Boolean, default: true },
    };

    connect() {
        super.connect();
        this.invoker = null;
        this.focusFrameOnLoad = false;
        this.onBeforeCache = () => this.#reset();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        document.addEventListener('click', this.#onOpenerClick);
        // A page with no drawer keeps the opener a full visit.
        this.openers = [
            ...document.querySelectorAll('[data-card-drawer-opener]'),
        ];
        this.openers.forEach((opener) => {
            opener.dataset.turboFrame = 'card-drawer-frame';
        });
        this.stopLive = on('board.card_changed', (change) =>
            this.cardChanged(change),
        );
    }

    disconnect() {
        super.disconnect();
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        document.removeEventListener('click', this.#onOpenerClick);
        this.openers.forEach((opener) => delete opener.dataset.turboFrame);
        this.stopLive?.();
    }

    get lazyFrame() {
        return new LazyFrame({
            frame: this.frameTarget,
            loading: this.loadingTarget,
            error: this.errorTarget,
        });
    }

    /** Someone else changed or deleted the card the drawer shows. */
    cardChanged(change) {
        if (change.local || change.own || !this.dialogTarget.open) return;
        if (this.frameTarget.hidden) return;
        const open = this.frameTarget.querySelector(
            '[data-card-drawer-card-id]',
        );
        if (open?.dataset.cardDrawerCardId !== change.cardId) return;
        if (change.change === 'deleted') {
            this.#showDeleted();
        } else if (change.contentChanged) {
            this.clashTargets.forEach((notice) => (notice.hidden = false));
        }
    }

    dialogTargetConnected(dialog) {
        super.dialogTargetConnected(dialog);
        dialog.addEventListener('close', this.#onClosed);
    }

    dialogTargetDisconnected(dialog) {
        super.dialogTargetDisconnected(dialog);
        dialog.removeEventListener('close', this.#onClosed);
    }

    /** A card link keeps its own click, which Turbo turns into the frame load. */
    prepare(event) {
        // A modified click opens the link in a new tab or window, and Turbo leaves the frame alone.
        if (isModifiedClick(event)) return;
        this.invoker = event.currentTarget;
        // The opener names what it opens, so the header draws before the frame loads.
        if (this.hasTitleTarget) {
            this.titleTarget.textContent =
                this.invoker.dataset.drawerTitle ?? '';
        }
        this.#startLoading();
        if (!this.dialogTarget.open) {
            this.open();
        }
        this.returnFocusTo = this.invoker;
    }

    loaded(event) {
        if (event.target !== this.frameTarget || !this.dialogTarget.open)
            return;
        const replacing = this.frameTarget.hidden === false;
        this.deletedTarget.hidden = true;
        this.lazyFrame.showReady();
        if (replacing && !prefersReducedMotion()) {
            this.frameTarget.animate?.([{ opacity: 0 }, { opacity: 1 }], {
                duration: 150,
                easing: 'ease',
            });
        }
        const opening = this.focusFrameOnLoad;
        this.focusFrameOnLoad = false;
        // A reader who already works in the new content keeps their focus.
        if (this.frameTarget.contains(document.activeElement)) return;
        // A form in the frame re-renders it too, and the drawer is modeless, so
        // work outside it is real work. Take only focus the render dropped.
        const active = document.activeElement;
        if (!opening && active && active !== document.body) return;
        // A flash renders above the header, so it holds the frame's first
        // button and would take the focus the card's own content deserves.
        const focusTarget = [
            ...this.frameTarget.querySelectorAll(
                'form input:not([type="hidden"]), [data-panel-tabs-target="tab"][aria-selected="true"], a, button',
            ),
        ].find((candidate) => !candidate.closest('.lp-flash'));
        focusTarget?.focus();
    }

    received(event) {
        const response = event.detail.fetchResponse;
        if (event.target !== this.frameTarget) {
            // A save the server cannot find the card for.
            if (
                response.statusCode === 404 &&
                event.target.closest?.(
                    '[data-card-drawer-saves-card][data-card-drawer-card-id]',
                )
            ) {
                event.preventDefault();
                this.#showDeleted();
            }
            return;
        }
        if (LazyFrame.refuses(event)) this.failed(event);
    }

    failed(event) {
        if (event.target !== this.frameTarget || !this.dialogTarget.open)
            return;
        event.preventDefault();
        this.lazyFrame.showError();
        this.errorTarget.querySelector('button').focus();
    }

    retry() {
        this.#startLoading();
        // The loading state carries no control, so the status takes the focus
        // the hidden Retry button had.
        this.loadingTarget.focus();
        this.frameTarget.reload();
    }

    /**
     * The board places an edited card on the local change, and a created one
     * from the stream that answers the create. card-drawer:saved reloads a
     * list that shows no board.
     */
    submitted(event) {
        if (!event.detail.success) return;
        // A lane adds or removes a board row, which no placement of one card shows.
        if (event.target.closest?.('[data-board-lane-form]')) {
            emit('board.columns_changed');
            return;
        }
        const form = event.target.closest?.('[data-card-drawer-saves-card]');
        if (!form) return;
        this.dispatch('saved');
        if (form.dataset.cardDrawerCardId) {
            emit('board.card_changed', {
                cardId: form.dataset.cardDrawerCardId,
                change: 'updated',
            });
        }
        const contentType = event.detail.fetchResponse?.contentType ?? '';
        if (
            'cardDrawerCreatesCard' in form.dataset &&
            contentType.startsWith(STREAM_TYPE)
        ) {
            this.close();
        }
    }

    close(event) {
        if (!this.dialogTarget.open) return;
        super.close(event);
    }

    restoreFocus() {
        this.#repairReturnFocus();
        super.restoreFocus();
        this.invoker = null;
    }

    #startLoading() {
        this.focusFrameOnLoad = true;
        this.deletedTarget.hidden = true;
        this.lazyFrame.showLoading();
    }

    #showDeleted() {
        this.loadingTarget.hidden = true;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = true;
        this.deletedTarget.hidden = false;
        this.deletedTarget.querySelector('button')?.focus();
    }

    // A board update can replace the link that opened the drawer; its twin keeps the place.
    #repairReturnFocus() {
        const href = this.invoker?.getAttribute('href');
        if (href && !this.returnFocusTo?.isConnected) {
            this.returnFocusTo = [
                ...this.element.querySelectorAll('a[href]'),
            ].find((link) => link.getAttribute('href') === href);
        }
    }

    // The top bar sits outside the controller element, so its opener is heard at the document.
    #onOpenerClick = (event) => {
        const opener = event.target.closest?.('[data-card-drawer-opener]');
        if (!opener || event.defaultPrevented) return;
        if (isModifiedClick(event)) return;
        // An opener that a frame load or a live update added after connect.
        opener.dataset.turboFrame = 'card-drawer-frame';
        if (!this.openers.includes(opener)) this.openers.push(opener);
        this.prepare({ currentTarget: opener });
    };

    #onClosed = () => {
        this.focusFrameOnLoad = false;
        this.deletedTarget.hidden = true;
        this.lazyFrame.showLoading();
        this.frameTarget.removeAttribute('src');
        this.frameTarget.replaceChildren();
    };

    #reset() {
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        this.#onClosed();
        this.invoker = null;
    }
}

function isModifiedClick(event) {
    return (
        event.button > 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    );
}
