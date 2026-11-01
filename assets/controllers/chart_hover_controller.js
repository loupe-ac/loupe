import { Controller } from '@hotwired/stimulus';

const GAP = 12;

/**
 * Shows the hover card of a chart target. The server prints each card in a
 * template that the target names with aria-describedby. The card is a clone of
 * that template with a fixed position, so the plot scroller cannot clip it.
 */
export default class extends Controller {
    static targets = ['surface', 'viewport'];

    connect() {
        this.card = null;
        this.onScroll = () => {
            if (this.card && this.active) {
                this.place(this.card, this.active);
            }
        };
    }

    disconnect() {
        this.hide();
    }

    show(event) {
        const target = event.currentTarget;
        const template = this.templateOf(target);
        this.hide();
        if (!template || !this.hasSurfaceTarget) {
            return;
        }
        const card = template.content.firstElementChild?.cloneNode(true);
        if (!card) {
            return;
        }
        card.setAttribute('data-chart-hover-card', '');
        this.surfaceTarget.appendChild(card);
        this.card = card;
        target.setAttribute('data-active', '');
        this.active = target;
        this.place(card, target);
        window.addEventListener('scroll', this.onScroll, {
            capture: true,
            passive: true,
        });
    }

    hide() {
        window.removeEventListener('scroll', this.onScroll, { capture: true });
        this.card?.remove();
        this.card = null;
        this.active?.removeAttribute('data-active');
        this.active = null;
    }

    /**
     * The card sits right of the target and flips left when it would leave the
     * visible plot. It stays inside the window from top to bottom, also when it
     * is taller than the plot.
     */
    place(card, target) {
        const visible = (
            this.hasViewportTarget ? this.viewportTarget : this.surfaceTarget
        ).getBoundingClientRect();
        const anchor = target.getBoundingClientRect();
        const size = card.getBoundingClientRect();
        const centre = anchor.left + anchor.width / 2;
        let left = centre + GAP;
        if (left + size.width > visible.right) {
            left = centre - GAP - size.width;
        }
        left = Math.max(visible.left, left);
        const top = Math.max(
            GAP,
            Math.min(anchor.top, window.innerHeight - size.height - GAP),
        );
        card.style.left = `${left}px`;
        card.style.top = `${top}px`;
    }

    templateOf(target) {
        const id = target.getAttribute('aria-describedby');
        if (!id) {
            return null;
        }
        const template = this.element.querySelector(`[id="${CSS.escape(id)}"]`);

        return template instanceof HTMLTemplateElement ? template : null;
    }
}
