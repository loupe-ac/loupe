import { Controller } from '@hotwired/stimulus';

const GAP = 12;

/**
 * Shows the hover card of a chart target. The server prints each card in a
 * template that the target names with aria-describedby. The card is a clone of
 * that template, placed inside the plot surface, so the page needs no library.
 */
export default class extends Controller {
    static targets = ['surface'];

    connect() {
        this.card = null;
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
    }

    hide() {
        this.card?.remove();
        this.card = null;
        this.active?.removeAttribute('data-active');
        this.active = null;
    }

    /** The card sits right of the target and flips left when it would leave the surface. */
    place(card, target) {
        const surface = this.surfaceTarget.getBoundingClientRect();
        const anchor = target.getBoundingClientRect();
        const size = card.getBoundingClientRect();
        const centre = anchor.left + anchor.width / 2 - surface.left;
        let left = centre + GAP;
        if (left + size.width > surface.width) {
            left = centre - GAP - size.width;
        }
        left = Math.max(0, left);
        const top = Math.max(
            0,
            Math.min(anchor.top - surface.top, surface.height - size.height),
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
