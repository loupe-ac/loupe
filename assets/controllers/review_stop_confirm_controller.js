import { Controller } from '@hotwired/stimulus';

/**
 * Sits on the Finish review form. While an agent review of the draft runs or
 * is paused, a submit first opens a second dialog that names the verdict.
 * The dialog lies outside the form, so this controller listens for its
 * confirm button itself.
 */
export default class extends Controller {
    static values = { active: Boolean, dialog: String };

    connect() {
        this.confirmed = false;
        document.addEventListener('click', this.#onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.#onDocumentClick);
    }

    /** Must run before form-draft#start, so a stopped submit starts no draft save. */
    check(event) {
        if (this.confirmed || !this.activeValue) return;

        const verdict = this.element.querySelector(
            'input[type="radio"]:checked',
        )?.value;
        const copy = verdict
            ? this.element.querySelector(
                  `[data-review-stop-confirm-copy="${CSS.escape(verdict)}"]`,
              )
            : null;
        const dialog = this.#dialogRoot();
        if (!copy || !dialog) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        dialog.querySelector('[data-review-stop-confirm-lead]').textContent =
            copy.dataset.lead;
        dialog.querySelector('[data-review-stop-confirm-body]').textContent =
            copy.dataset.body;
        dialog.querySelector('[data-review-stop-confirm-confirm]').textContent =
            copy.dataset.confirm;
        window.dispatchEvent(new CustomEvent('review:stop-confirm'));
    }

    #dialogRoot() {
        return document.getElementById(this.dialogValue);
    }

    #onDocumentClick = (event) => {
        const button = event.target.closest?.(
            '[data-review-stop-confirm-confirm]',
        );
        if (!button || !this.#dialogRoot()?.contains(button)) return;

        this.confirmed = true;
        try {
            this.element.requestSubmit();
        } finally {
            this.confirmed = false;
        }
    };
}
