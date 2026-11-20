/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['clearButton', 'field'];
    static values = { ignore: Array, sync: Boolean };

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
        this.syncFromFrame = (event) => {
            if (
                this.syncValue &&
                event.target.id &&
                event.target.id === this.element.dataset.turboFrame
            ) {
                this.#syncFromAddress(
                    event.detail?.fetchResponse?.response?.url,
                );
            }
        };
        document.addEventListener('turbo:frame-render', this.syncFromFrame);
    }

    disconnect() {
        document.removeEventListener('click', this.clearFromOutside);
        document.removeEventListener('turbo:frame-render', this.syncFromFrame);
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

    /**
     * A form that sits outside the frame it swaps keeps its own state, so a
     * link inside the frame that changes the filter, such as a button of an
     * empty list, leaves the switch and the selects stale. After a render the
     * form copies its checkboxes and selects from the address the frame
     * loaded. A response for another path, such as a row action, changes
     * nothing, and a control with focus is left alone.
     */
    #syncFromAddress(address) {
        let url;
        try {
            url = new URL(
                address ?? window.location.href,
                window.location.href,
            );
        } catch {
            return;
        }
        if (url.pathname !== new URL(this.element.action).pathname) return;
        for (const element of this.element.elements) {
            if (!element.name || element === document.activeElement) continue;
            if (element.type === 'checkbox') {
                element.checked = url.searchParams
                    .getAll(element.name)
                    .includes(element.value);
            } else if (
                element.tagName === 'SELECT' &&
                url.searchParams.has(element.name)
            ) {
                element.value = url.searchParams.get(element.name);
            }
        }
        this.#updateClearButton();
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
