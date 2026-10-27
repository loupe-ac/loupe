import { Controller } from '@hotwired/stimulus';
import { followMenuPanel } from '../lib/menu_placement.js';

export default class extends Controller {
    static targets = ['trigger', 'panel'];

    connect() {
        this.stopFollowing = () => {};
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (
                event.key === 'Escape' &&
                !this.panelTarget.hidden &&
                !event.target.closest('dialog[open]')
            ) {
                event.preventDefault();
                event.stopPropagation();
                this.close();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        this.element.addEventListener('keydown', this.onKeydown);
        this.onBeforeCache = () => this.close({ restoreFocus: false });
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.#setExpanded(!this.panelTarget.hidden);
    }

    disconnect() {
        this.stopFollowing();
        document.removeEventListener('click', this.onDocumentClick);
        this.element.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.panelTarget.hidden) {
            this.open();
        } else {
            this.close();
        }
    }

    open() {
        this.panelTarget.hidden = false;
        this.#setExpanded(true);
        this.stopFollowing();
        this.stopFollowing = followMenuPanel(this.panelTarget);
    }

    close({ restoreFocus = true } = {}) {
        if (this.panelTarget.hidden) {
            return;
        }
        const focusInside = this.panelTarget.contains(document.activeElement);
        this.panelTarget.hidden = true;
        this.stopFollowing();
        this.#setExpanded(false);
        if (restoreFocus && focusInside && this.hasTriggerTarget) {
            this.triggerTarget.focus();
        }
    }

    #setExpanded(expanded) {
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute(
                'aria-expanded',
                expanded ? 'true' : 'false',
            );
        }
    }
}
