import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

/*
 * Dismisses a flash message. A flash with an auto-dismiss value fades out
 * after that many milliseconds. Any other flash stays until dismissed.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { autoDismiss: Number };

    connect() {
        this.#startTimer();
        this.element.addEventListener('turbo:morph-element', this.#onMorph);
    }

    disconnect() {
        this.#stopTimer();
        this.element.removeEventListener('turbo:morph-element', this.#onMorph);
    }

    // A morph can reuse this element for a new message, so the delay starts over.
    #onMorph = () => {
        this.#startTimer();
    };

    #startTimer() {
        this.#stopTimer();
        if (this.autoDismissValue > 0) {
            this.timer = setTimeout(
                () => this.dismiss(),
                this.autoDismissValue,
            );
        }
    }

    #stopTimer() {
        clearTimeout(this.timer);
        this.timer = null;
    }

    dismiss() {
        this.#stopTimer();
        if (prefersReducedMotion()) {
            this.element.remove();
            return;
        }
        const animation = this.element.animate(
            [
                { opacity: 1, transform: 'translateY(0)' },
                { opacity: 0, transform: 'translateY(-4px)' },
            ],
            { duration: 120, easing: 'ease-out', fill: 'forwards' },
        );
        animation.finished.then(() => this.element.remove()).catch(() => {});
    }
}
