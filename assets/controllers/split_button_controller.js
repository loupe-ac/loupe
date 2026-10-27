import { Controller } from '@hotwired/stimulus';
import { followMenuPanel } from '../lib/menu_placement.js';

const ITEMS = '[role="menuitem"]:not([aria-disabled="true"])';

/**
 * A split button: the main part acts, and the chevron opens a menu of the other
 * actions. The menu is hidden until opened. Escape and a click outside close
 * it, and the arrow keys move between its rows.
 */
export default class extends Controller {
    static targets = ['trigger', 'panel'];

    connect() {
        // A split button with no menu rows draws its main part alone, with no toggle.
        if (!this.hasTriggerTarget) {
            return;
        }
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close({ restoreFocus: false });
            }
        };
        this.stopFollowing = () => {};
        this.onKeydown = (event) => this.#keydown(event);
        this.onBeforeCache = () => this.close({ restoreFocus: false });
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.element.addEventListener('keydown', this.onKeydown);
        this.#setExpanded(this.#isOpen());
    }

    disconnect() {
        this.stopFollowing?.();
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    toggle(event) {
        event.preventDefault();
        if (this.#isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.panelTarget.hidden = false;
        this.#setExpanded(true);
        this.stopFollowing();
        this.stopFollowing = followMenuPanel(this.panelTarget);
    }

    close({ restoreFocus = true } = {}) {
        if (!this.#isOpen()) {
            return;
        }
        const focusInside = this.panelTarget.contains(document.activeElement);
        this.panelTarget.hidden = true;
        this.stopFollowing();
        this.#setExpanded(false);
        if (restoreFocus && focusInside) {
            this.triggerTarget.focus();
        }
    }

    #isOpen() {
        return !this.panelTarget.hidden;
    }

    #setExpanded(expanded) {
        this.triggerTarget.setAttribute(
            'aria-expanded',
            expanded ? 'true' : 'false',
        );
    }

    #keydown(event) {
        if (event.key === 'Escape') {
            if (this.#isOpen() && !event.target.closest('dialog[open]')) {
                event.preventDefault();
                event.stopPropagation();
                this.close();
            }

            return;
        }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }
        // The chevron opens the menu with an arrow key, as a native menu button does.
        if (!this.#isOpen()) {
            if (event.target === this.triggerTarget) {
                event.preventDefault();
                this.open();
                this.#move(event.key === 'ArrowDown' ? 1 : -1);
            }

            return;
        }
        event.preventDefault();
        this.#move(event.key === 'ArrowDown' ? 1 : -1);
    }

    #move(step) {
        const rows = [...this.panelTarget.querySelectorAll(ITEMS)];
        if (rows.length === 0) {
            return;
        }
        const current = rows.indexOf(document.activeElement);
        let next;
        if (current === -1) {
            next = step === 1 ? 0 : rows.length - 1;
        } else {
            next = (current + step + rows.length) % rows.length;
        }
        rows[next].focus();
    }
}
