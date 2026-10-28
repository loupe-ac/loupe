import { Controller } from '@hotwired/stimulus';

const LINK = 'a[data-ref-tooltip-title], a[data-ref-tooltip-line]';
const GAP = 8;
const MARGIN = 8;

const focusVisible = (element) => {
    try {
        return element.matches(':focus-visible');
    } catch {
        return true;
    }
};

/**
 * Shows the title and the state of a card or pull request link in one shared
 * tooltip. The tooltip sits outside the prose, because comment anchors count
 * the prose's text. A popover draws in the top layer, above the card drawer.
 */
export default class extends Controller {
    static targets = ['tooltip', 'title', 'line'];

    connect() {
        this.link = null;
        this.hovered = false;
        this.focused = false;
        this.onScroll = () => this.hide();
        this.element.addEventListener('pointerover', this.onPointerOver);
        this.element.addEventListener('pointerout', this.onPointerOut);
        this.element.addEventListener('focusin', this.onFocusIn);
        this.element.addEventListener('focusout', this.onFocusOut);
        this.element.addEventListener('keydown', this.onKeyDown);
        this.element.addEventListener('click', this.onClick);
        document.addEventListener('turbo:before-cache', this.onScroll);
    }

    disconnect() {
        this.element.removeEventListener('pointerover', this.onPointerOver);
        this.element.removeEventListener('pointerout', this.onPointerOut);
        this.element.removeEventListener('focusin', this.onFocusIn);
        this.element.removeEventListener('focusout', this.onFocusOut);
        this.element.removeEventListener('keydown', this.onKeyDown);
        this.element.removeEventListener('click', this.onClick);
        document.removeEventListener('turbo:before-cache', this.onScroll);
        this.hide();
    }

    onPointerOver = (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        const link = event.target.closest?.(LINK);
        if (link) {
            this.show(link);
            this.hovered = true;
        }
    };

    onPointerOut = (event) => {
        const link = event.target.closest?.(LINK);
        if (link && link === this.link && !link.contains(event.relatedTarget)) {
            this.hovered = false;
            this.#hideWhenIdle();
        }
    };

    onFocusIn = (event) => {
        const link = event.target.closest?.(LINK);
        if (link && focusVisible(link)) {
            this.show(link);
            this.focused = true;
        }
    };

    onFocusOut = (event) => {
        if (event.target === this.link) {
            this.focused = false;
            this.#hideWhenIdle();
        }
    };

    onKeyDown = (event) => {
        if (event.key === 'Escape') {
            this.hide();
        }
    };

    /** A press follows the link, so the tooltip gives way. */
    onClick = (event) => {
        if (event.target.closest?.(LINK)) {
            this.hide();
        }
    };

    show(link) {
        if (!this.hasTooltipTarget || link === this.link) {
            return;
        }
        this.hide();
        const title = link.dataset.refTooltipTitle ?? '';
        const line = link.dataset.refTooltipLine ?? '';
        this.titleTarget.textContent = title;
        this.titleTarget.hidden = title === '';
        this.lineTarget.textContent = line;
        this.lineTarget.hidden = line === '';
        this.link = link;
        link.setAttribute('aria-describedby', this.tooltipTarget.id);
        this.#showPopover();
        this.#place(link);
        this.tooltipTarget.setAttribute('data-open', '');
        window.addEventListener('scroll', this.onScroll, true);
    }

    hide() {
        window.removeEventListener('scroll', this.onScroll, true);
        this.link?.removeAttribute('aria-describedby');
        this.link = null;
        this.hovered = false;
        this.focused = false;
        if (!this.hasTooltipTarget) {
            return;
        }
        this.tooltipTarget.removeAttribute('data-open');
        try {
            this.tooltipTarget.hidePopover?.();
        } catch {
            // Already hidden, or the element left the document.
        }
    }

    #hideWhenIdle() {
        if (!this.hovered && !this.focused) {
            this.hide();
        }
    }

    #showPopover() {
        try {
            this.tooltipTarget.showPopover?.();
        } catch {
            // Already showing, which is the state this asks for.
        }
    }

    /** A closed popover has no size, so this runs after the popover opens. */
    #place(link) {
        const tooltip = this.tooltipTarget;
        tooltip.style.left = '0px';
        tooltip.style.top = '0px';
        const origin = tooltip.getBoundingClientRect();
        const anchor = link.getBoundingClientRect();
        const left = Math.max(
            MARGIN,
            Math.min(anchor.left, window.innerWidth - origin.width - MARGIN),
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
