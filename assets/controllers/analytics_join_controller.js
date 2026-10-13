import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/**
 * Sits on the confirmation the server renders after a saved waitlist email,
 * and sends the join event once.
 */
export default class extends Controller {
    static values = { form: String };

    connect() {
        track('waitlist-join', { form: this.formValue });
        // A page restored from the Turbo cache must not send the event again.
        this.element.removeAttribute('data-controller');
    }
}
