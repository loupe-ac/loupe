import { Controller } from '@hotwired/stimulus';

/**
 * The rename form of a Dashboard tile. The form replaces the name until the
 * person saves or cancels. The tile carries `data-renaming` while it is open,
 * so the live update waits.
 */
export default class extends Controller {
    static targets = ['renameForm', 'renameInput'];

    rename(event) {
        event.preventDefault();
        this.closeMenu();
        this.#title().hidden = true;
        this.renameFormTarget.hidden = false;
        this.element.dataset.renaming = '';
        this.renameInputTarget.focus();
        this.renameInputTarget.select();
    }

    cancelRename() {
        if (this.renameFormTarget.hidden) {
            return;
        }
        this.renameFormTarget.reset();
        this.renameFormTarget.hidden = true;
        this.#title().hidden = false;
        delete this.element.dataset.renaming;
    }

    /** Presses the menu trigger when the panel is open, so the popover closes itself. */
    closeMenu() {
        this.element
            .querySelector(
                '[data-popover-target="trigger"][aria-expanded="true"]',
            )
            ?.click();
    }

    #title() {
        return this.element.querySelector('[data-graph-title]');
    }
}
