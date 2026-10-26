import { Controller } from '@hotwired/stimulus';

const CLOSE_DELAY = 150;
const GAP = 8;
const MARGIN = 8;
const OVERHANG = 4;

const focusVisible = (element) => {
    try {
        return element.matches(':focus-visible');
    } catch {
        return true;
    }
};

/**
 * Opens a tooltip that holds a link. It stays open while the pointer moves from
 * the anchor into the tooltip, and while the keyboard focus is inside it. The
 * tooltip is fixed, so a scrolling column cannot clip it. A popover also draws
 * it in the top layer, above the sidebar.
 */
export default class extends Controller {
    static targets = ['tooltip'];
    static values = { align: { type: String, default: 'end' } };

    connect() {
        this.closeTimer = null;
        this.returningFocus = false;
        this.opened = false;
        this.onScroll = (event) => {
            if (this.scrollMovesAnchor(event.target)) {
                this.close();
            }
        };
        // A morph of the lane head drops the open mark and the placement the server did not render.
        this.onMorph = () => {
            if (this.opened && this.hasTooltipTarget) {
                this.tooltipTarget.setAttribute('data-open', '');
                this.place();
            }
        };
        document.addEventListener('turbo:morph-element', this.onMorph);
        this.onOutsidePress = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
    }

    disconnect() {
        document.removeEventListener('turbo:morph-element', this.onMorph);
        this.close();
    }

    open(event) {
        if (this.returningFocus) {
            return;
        }
        if (event?.type === 'focusin' && !focusVisible(event.target)) {
            return;
        }
        this.cancelClose();
        if (this.tooltipTarget.hasAttribute('data-open')) {
            return;
        }
        this.showPopover();
        this.place();
        this.tooltipTarget.setAttribute('data-open', '');
        this.opened = true;
        window.addEventListener('scroll', this.onScroll, true);
        document.addEventListener('pointerdown', this.onOutsidePress, true);
    }

    close(event) {
        this.cancelClose();
        this.opened = false;
        window.removeEventListener('scroll', this.onScroll, true);
        document.removeEventListener('pointerdown', this.onOutsidePress, true);
        if (!this.hasTooltipTarget) {
            return;
        }
        this.tooltipTarget.removeAttribute('data-open');
        this.hidePopover();
        // Escape from the link would leave the focus on a hidden element.
        if (
            event?.type === 'keydown' &&
            this.tooltipTarget.contains(document.activeElement)
        ) {
            this.returnFocus();
        }
    }

    /** Only a scroll of the page or of a box around the anchor moves it, so another box scrolling leaves the tooltip alone. */
    scrollMovesAnchor(target) {
        return (
            !(target instanceof Node) ||
            target === document ||
            target.contains(this.element)
        );
    }

    returnFocus() {
        const anchor = this.element.matches('[tabindex]')
            ? this.element
            : this.element.querySelector('[tabindex]');
        this.returningFocus = true;
        anchor?.focus();
        this.returningFocus = false;
    }

    /** A touch has no hover to return to, so a touch tooltip waits for a press outside it. */
    scheduleClose(event) {
        if (event?.pointerType === 'touch') {
            return;
        }
        this.cancelClose();
        this.closeTimer = setTimeout(() => {
            const active = document.activeElement;
            if (this.element.contains(active) && focusVisible(active)) {
                return;
            }
            this.close();
        }, CLOSE_DELAY);
    }

    leaveFocus(event) {
        if (!this.element.contains(event.relatedTarget)) {
            this.close();
        }
    }

    /** A mouse press on the mark starts a drag of its card, so the tooltip gives way. */
    pressed(event) {
        if (event.pointerType === 'mouse') {
            this.close();
        }
    }

    cancelClose() {
        clearTimeout(this.closeTimer);
        this.closeTimer = null;
    }

    hasPopover() {
        return (
            this.tooltipTarget.hasAttribute('popover') &&
            typeof this.tooltipTarget.showPopover === 'function'
        );
    }

    /** A closed popover has no size, so this runs before place(). */
    showPopover() {
        if (!this.hasPopover()) {
            return;
        }
        try {
            this.tooltipTarget.showPopover();
        } catch {
            // Already showing, which is the state this asks for.
        }
    }

    hidePopover() {
        if (!this.hasPopover()) {
            return;
        }
        try {
            this.tooltipTarget.hidePopover();
        } catch {
            // Already hidden, or the element left the document.
        }
    }

    place() {
        const tooltip = this.tooltipTarget;
        tooltip.style.left = '0px';
        tooltip.style.top = '0px';
        const origin = tooltip.getBoundingClientRect();
        const anchor = this.element.getBoundingClientRect();
        // A mark that ends its row hangs the tooltip from its right edge, and a mark that starts it from its left edge.
        const preferred =
            this.alignValue === 'start'
                ? anchor.left - OVERHANG
                : anchor.right + OVERHANG - origin.width;
        const left = Math.max(
            MARGIN,
            Math.min(preferred, window.innerWidth - origin.width - MARGIN),
        );
        let top = anchor.bottom + GAP;
        const above = anchor.top - GAP - origin.height;
        if (
            top + origin.height > window.innerHeight - MARGIN &&
            above >= MARGIN
        ) {
            top = above;
        }
        top = Math.max(
            MARGIN,
            Math.min(top, window.innerHeight - origin.height - MARGIN),
        );
        // A transformed ancestor moves the fixed origin, so offset from where 0,0 landed.
        tooltip.style.left = `${left - origin.left}px`;
        tooltip.style.top = `${top - origin.top}px`;
    }
}
