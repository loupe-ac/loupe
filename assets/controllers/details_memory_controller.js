/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';

/** What the reader chose for each section, by key. A reloaded frame renders a new element, so the choice lives here. */
export const choices = new Map();

/**
 * Keeps a `<details>` section open or closed as the reader left it, across the reloads of a live page.
 * Until the reader toggles it, the server decides.
 */
export default class extends Controller {
    static values = { key: String };

    connect() {
        if (choices.has(this.keyValue)) {
            this.element.open = choices.get(this.keyValue);
        }
        this.element.addEventListener('toggle', this.remember);
    }

    disconnect() {
        this.element.removeEventListener('toggle', this.remember);
    }

    remember = () => {
        choices.set(this.keyValue, this.element.open);
    };
}
