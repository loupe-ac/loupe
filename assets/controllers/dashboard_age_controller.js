import { Controller } from '@hotwired/stimulus';

export const TICK_MILLISECONDS = 30_000;

/**
 * Keeps the "Updated N min ago" line of the Dashboard true. The line counts
 * from the oldest tile that shows a result, so a lazy tile that arrives, or
 * a grid that a live update replaces, moves it. It counts from the server
 * clock, so a wrong clock on this machine moves nothing.
 */
export default class extends Controller {
    static targets = ['line'];

    static values = { now: Number, text: String };

    connect() {
        this.offset = this.nowValue - Date.now() / 1000;
        this.observer = new MutationObserver(() => this.render());
        this.observer.observe(this.element, { childList: true, subtree: true });
        this.timer = setInterval(() => this.render(), TICK_MILLISECONDS);
        this.render();
    }

    disconnect() {
        this.observer?.disconnect();
        clearInterval(this.timer);
    }

    render() {
        if (!this.hasLineTarget) {
            return;
        }
        const stamps = [...this.element.querySelectorAll('[data-computed-at]')]
            .map((tile) => Number(tile.dataset.computedAt))
            .filter(Number.isFinite);
        if (stamps.length === 0) {
            this.lineTarget.hidden = true;
            return;
        }
        const seconds = Date.now() / 1000 + this.offset - Math.min(...stamps);
        const minutes = Math.max(0, Math.floor(seconds / 60));
        const text = this.textValue.replace('%minutes%', String(minutes));
        if (this.lineTarget.textContent !== text) {
            this.lineTarget.textContent = text;
        }
        this.lineTarget.hidden = false;
    }
}
