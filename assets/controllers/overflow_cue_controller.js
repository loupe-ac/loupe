import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

const ACTIVE_SELECTOR =
    '[aria-selected="true"], [aria-current="page"], [aria-pressed="true"]';
const SCROLL_FRACTION = 0.9;
const EDGE_TOLERANCE = 1;
const ARROW_FALLBACK = 44;
const ACTIVE_ATTRIBUTES = ['aria-selected', 'aria-current', 'aria-pressed'];

export default class extends Controller {
    static targets = ['viewport', 'active', 'prev', 'next'];
    static values = { mode: { type: String, default: 'scroll' } };

    connect() {
        this.update = this.update.bind(this);
        this.onScroll = () => {
            this.update();
            this.dispatch('scrolled');
        };
        this.viewportTarget.addEventListener('scroll', this.onScroll, {
            passive: true,
        });
        this.revealPending = true;
        this.resizeObserver = new ResizeObserver(() =>
            this.revealPending ? this.reveal() : this.update(),
        );
        this.resizeObserver.observe(this.viewportTarget);
        for (const child of this.viewportTarget.children) {
            this.resizeObserver.observe(child);
        }
        this.activeObserver = new MutationObserver(() => this.reveal());
        this.activeObserver.observe(this.viewportTarget, {
            subtree: true,
            attributes: true,
            attributeFilter: ACTIVE_ATTRIBUTES,
        });
        this.update();
        this.reveal();
    }

    disconnect() {
        this.viewportTarget.removeEventListener('scroll', this.onScroll);
        this.resizeObserver?.disconnect();
        this.resizeObserver = null;
        this.activeObserver?.disconnect();
        this.activeObserver = null;
    }

    update() {
        const { scrollLeft, scrollWidth, clientWidth } = this.viewportTarget;
        this.element.toggleAttribute(
            'data-overflow-start',
            scrollLeft > EDGE_TOLERANCE,
        );
        this.element.toggleAttribute(
            'data-overflow-end',
            scrollLeft + clientWidth < scrollWidth - EDGE_TOLERANCE,
        );
        this.#keepArrowFocus();
    }

    #keepArrowFocus() {
        if (!this.hasPrevTarget || !this.hasNextTarget) {
            return;
        }
        const focused = document.activeElement;
        if (focused !== this.prevTarget && focused !== this.nextTarget) {
            return;
        }
        const start = this.element.hasAttribute('data-overflow-start');
        const end = this.element.hasAttribute('data-overflow-end');
        const onPrev = focused === this.prevTarget;
        if ((onPrev ? start : end) || 'scroll' !== this.modeValue) {
            return;
        }
        const other = onPrev ? this.nextTarget : this.prevTarget;
        const otherShown = onPrev ? end : start;
        (otherShown ? other : (this.#active() ?? other)).focus({
            preventScroll: true,
        });
    }

    #active() {
        return this.hasActiveTarget
            ? this.activeTarget
            : this.viewportTarget.querySelector(ACTIVE_SELECTOR);
    }

    reveal() {
        const active = this.#active();
        if (!active) {
            this.revealPending = false;
            this.update();

            return;
        }
        const strip = this.viewportTarget;
        const stripBox = strip.getBoundingClientRect();
        this.revealPending = stripBox.right <= stripBox.left;
        if (this.revealPending) {
            this.update();

            return;
        }
        const box = active.getBoundingClientRect();
        const room = this.#arrowWidth();
        if (box.left < stripBox.left + room) {
            strip.scrollLeft += box.left - stripBox.left - room;
        } else if (box.right > stripBox.right - room) {
            strip.scrollLeft += box.right - stripBox.right + room;
        }
        this.update();
    }

    #arrowWidth() {
        const arrow = this.hasNextTarget ? this.nextTarget : null;

        return arrow?.getBoundingClientRect().width || ARROW_FALLBACK;
    }

    scrollPrev() {
        this.#move(-1);
    }

    scrollNext() {
        this.#move(1);
    }

    #move(direction) {
        if ('step' === this.modeValue) {
            this.dispatch('step', { detail: { direction } });

            return;
        }
        this.viewportTarget.scrollBy({
            left: direction * this.viewportTarget.clientWidth * SCROLL_FRACTION,
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
    }
}
