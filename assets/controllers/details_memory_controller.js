/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';

/** What the reader chose for each section, by key. A reloaded frame renders a new element, so the choice lives here. */
export const choices = new Map();

// A frame reload fires no visit, so the choices last for one visit of the page only.
// A refresh of the same page morphs it, so the choices outlast that visit.
document.addEventListener('turbo:visit', (event) => {
    const { url, action } = event.detail ?? {};
    const refresh =
        action === 'replace' &&
        url !== undefined &&
        new URL(url, window.location.href).pathname ===
            window.location.pathname;
    if (!refresh) {
        choices.clear();
    }
});

/**
 * Keeps a `<details>` section open or closed as the reader left it, across the reloads of a live page.
 * Until the reader toggles it, the server decides. A rendered `open` also fires `toggle`, so only a click counts.
 */
export default class extends Controller {
    static values = { key: String };

    connect() {
        this.clicked = false;
        if (choices.has(this.keyValue)) {
            this.element.open = choices.get(this.keyValue);
        }
        this.element.addEventListener('click', this.watch);
        this.element.addEventListener('toggle', this.remember);
        this.element.addEventListener(
            'turbo:before-morph-attribute',
            this.keep,
        );
    }

    disconnect() {
        this.element.removeEventListener('click', this.watch);
        this.element.removeEventListener('toggle', this.remember);
        this.element.removeEventListener(
            'turbo:before-morph-attribute',
            this.keep,
        );
    }

    // A morph keeps the element, so connect does not run again to apply the choice.
    keep = (event) => {
        if (
            event.target === this.element &&
            event.detail.attributeName === 'open' &&
            choices.has(this.keyValue)
        ) {
            event.preventDefault();
        }
    };

    watch = (event) => {
        if (event.target.closest('summary')?.parentElement === this.element) {
            this.clicked = true;
        }
    };

    remember = () => {
        if (!this.clicked) {
            return;
        }
        this.clicked = false;
        choices.set(this.keyValue, this.element.open);
    };
}
