import { Controller } from '@hotwired/stimulus';

/**
 * A waiting Dashboard tile shows a spinner. When no result came after the
 * delay, the slow tile from the template replaces the spinner. A new tile
 * (the live update or the Retry answer) replaces the whole frame, so the
 * timer starts again with it. A reload starts it again too.
 */
export default class extends Controller {
    static targets = ['slow'];

    static values = { delay: Number };

    connect() {
        this.timer = setTimeout(() => this.show(), this.delayValue * 1000);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    show() {
        if (!this.hasSlowTarget) {
            return;
        }
        const slow = this.slowTarget.content.cloneNode(true);
        this.element.replaceChildren(slow);
    }
}
