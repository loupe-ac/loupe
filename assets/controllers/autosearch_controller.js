/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['clearButton', 'field'];
    static values = { ignore: Array };

    connect() {
        this.timeout = null;
        this.#updateClearButton();
        this.clearFromOutside = (event) => {
            const proxy = event.target.closest?.('[data-clear-filter-form]');
            if (
                proxy &&
                this.element.id &&
                proxy.dataset.clearFilterForm === this.element.id
            ) {
                this.clear(event);
            }
        };
        document.addEventListener('click', this.clearFromOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.clearFromOutside);
        clearTimeout(this.timeout);
    }

    revealField(event) {
        event.target.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: 'instant',
        });
    }

    search() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => {
            this.element.requestSubmit();
        }, 300);
        this.#updateClearButton();
    }

    /**
     * A bar that swaps a frame keeps its form across the swap, so a plain
     * link would leave the old values in the fields. The link empties the
     * fields and submits the form instead.
     */
    clear(event) {
        event.preventDefault();
        clearTimeout(this.timeout);
        const ignored = this.hasIgnoreValue
            ? this.ignoreValue
            : ['sort', 'dir'];
        for (const element of this.element.elements) {
            if (!element.name || ignored.includes(element.name)) continue;
            if (element.type === 'hidden') continue;
            if (element.type === 'checkbox') {
                element.checked = false;
            } else {
                element.value = '';
            }
        }
        this.#updateClearButton();
        this.element.requestSubmit();
    }

    #hasActiveFilters() {
        const ignored = this.hasIgnoreValue
            ? this.ignoreValue
            : ['sort', 'dir'];
        return Array.from(this.element.elements).some((element) => {
            if (!element.name || ignored.includes(element.name)) return false;
            if (element.type === 'checkbox') return element.checked;
            return element.value !== '';
        });
    }

    #updateClearButton() {
        if (!this.hasClearButtonTarget) return;
        const inactive = !this.#hasActiveFilters();
        this.clearButtonTarget.classList.toggle('opacity-40', inactive);
        this.clearButtonTarget.classList.toggle(
            'pointer-events-none',
            inactive,
        );
    }
}
