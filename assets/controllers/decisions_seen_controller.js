import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['form'];

    connect() {
        this.observer = new MutationObserver(() => this.mark());
        this.observer.observe(this.element, {
            attributes: true,
            attributeFilter: ['hidden'],
        });
        this.mark();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    mark() {
        if (this.element.hidden || !this.hasFormTarget) {
            return;
        }
        this.formTarget.requestSubmit();
    }
}
