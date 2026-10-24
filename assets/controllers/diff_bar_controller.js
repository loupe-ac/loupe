import { Controller } from '@hotwired/stimulus';

/**
 * Opens and closes the part of the diff bar that a phone folds away behind the
 * one-line summary: the back link, the version pickers and the view menu. Above
 * the compact width the bar shows everything, so the state is inert there.
 *
 * Usage:
 *   <div class="lp-diff-bar" data-controller="diff-bar">
 *     <button data-diff-bar-target="trigger" data-action="diff-bar#toggle">…</button>
 *     <div id="…">…</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['trigger'];

    toggle() {
        this.#set(!this.element.hasAttribute('data-open'));
    }

    close() {
        if (!this.element.hasAttribute('data-open')) {
            return;
        }
        this.#set(false);
        this.triggerTarget.focus();
    }

    disconnect() {
        // Turbo caches the page as it stands, so an open bar would come back
        // open on the restored snapshot.
        this.#set(false);
    }

    #set(open) {
        this.element.toggleAttribute('data-open', open);
        this.triggerTarget.setAttribute('aria-expanded', String(open));
    }
}
