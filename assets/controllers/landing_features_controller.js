import { Controller } from '@hotwired/stimulus';

/**
 * The feature carousel of the home page. One slide per feature sits in a track,
 * and the picked feature shows its description in the list.
 */
export default class extends Controller {
    static targets = ['item', 'description', 'slide', 'track'];
    static values = { index: { type: Number, default: 0 } };

    pick(event) {
        this.show(event.params.index);
    }

    previous() {
        this.show(this.indexValue - 1);
    }

    next() {
        this.show(this.indexValue + 1);
    }

    show(index) {
        const count = this.slideTargets.length;
        this.indexValue = (index + count) % count;
    }

    indexValueChanged() {
        const active = this.indexValue;

        this.trackTarget.style.transform = `translateX(${-100 * active}%)`;
        this.itemTargets.forEach((item, position) => {
            item.setAttribute('aria-pressed', String(position === active));
        });
        this.descriptionTargets.forEach((description, position) => {
            description.hidden = position !== active;
        });
        this.slideTargets.forEach((slide, position) => {
            slide.setAttribute('aria-hidden', String(position !== active));
            slide.inert = position !== active;
        });
    }
}
