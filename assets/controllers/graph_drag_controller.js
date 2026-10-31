import { Controller } from '@hotwired/stimulus';

/** A press must travel this far before it counts as a drag. */
export const DRAG_THRESHOLD = 5;

/** The step count the move route takes for a tile that goes from one place to another. */
export const stepsBetween = (fromIndex, toIndex) => toIndex - fromIndex;

/**
 * The index of the tile under a point. A point between tiles, or beside them,
 * counts for the tile whose centre is nearest.
 *
 * @param {{left: number, right: number, top: number, bottom: number}[]} rectangles
 */
export function indexAtPoint(rectangles, x, y) {
    const inside = rectangles.findIndex(
        (rectangle) =>
            x >= rectangle.left &&
            x <= rectangle.right &&
            y >= rectangle.top &&
            y <= rectangle.bottom,
    );
    if (inside !== -1) {
        return inside;
    }
    let nearest = 0;
    let nearestDistance = Infinity;
    rectangles.forEach((rectangle, index) => {
        const distance = Math.hypot(
            x - (rectangle.left + rectangle.right) / 2,
            y - (rectangle.top + rectangle.bottom) / 2,
        );
        if (distance < nearestDistance) {
            nearest = index;
            nearestDistance = distance;
        }
    });

    return nearest;
}

const DRAGGING_CLASS = 'lp-dashboard-grid__cell--dragging';
const DROP_CLASS = 'lp-dashboard-grid__cell--drop';

/**
 * Reorders the tiles of the Dashboard. A person drags a tile by its grip,
 * and the drop posts the move form of that tile with the step count. The
 * server answers with a redirect, so the page shows the saved order. The
 * Move up and Move down menu items are the keyboard way. The grid carries
 * `data-dragging` during a drag, so the live update waits.
 */
export default class extends Controller {
    connect() {
        this.press = null;
        this.onDown = (event) => this.#down(event);
        this.onMove = (event) => this.#move(event);
        this.onUp = (event) => this.#up(event);
        this.onCancel = () => this.#end();
        this.element.addEventListener('pointerdown', this.onDown);
        this.element.addEventListener('pointermove', this.onMove);
        this.element.addEventListener('pointerup', this.onUp);
        this.element.addEventListener('pointercancel', this.onCancel);
    }

    disconnect() {
        this.#end();
        this.element.removeEventListener('pointerdown', this.onDown);
        this.element.removeEventListener('pointermove', this.onMove);
        this.element.removeEventListener('pointerup', this.onUp);
        this.element.removeEventListener('pointercancel', this.onCancel);
    }

    #cells() {
        return [...this.element.children].filter(
            (child) => child.dataset.graphId !== undefined,
        );
    }

    #down(event) {
        if (
            this.press !== null ||
            (event.pointerType === 'mouse' && event.button !== 0)
        ) {
            return;
        }
        const grip = event.target.closest?.('[data-graph-grip]');
        const cell = grip?.closest('[data-graph-id]');
        if (!cell) {
            return;
        }
        const cells = this.#cells();
        this.press = {
            cell,
            grip,
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            from: cells.indexOf(cell),
            to: cells.indexOf(cell),
            dragging: false,
        };
        grip.setPointerCapture?.(event.pointerId);
    }

    #move(event) {
        const press = this.press;
        if (press === null || event.pointerId !== press.pointerId) {
            return;
        }
        if (!press.dragging) {
            const distance = Math.hypot(
                event.clientX - press.x,
                event.clientY - press.y,
            );
            if (distance < DRAG_THRESHOLD) {
                return;
            }
            press.dragging = true;
            this.element.dataset.dragging = '';
            press.cell.classList.add(DRAGGING_CLASS);
        }
        const cells = this.#cells();
        press.to = this.#indexUnder(event, cells);
        cells.forEach((cell, index) =>
            cell.classList.toggle(
                DROP_CLASS,
                index === press.to && index !== press.from,
            ),
        );
    }

    #indexUnder(event, cells) {
        return indexAtPoint(
            cells.map((cell) => cell.getBoundingClientRect()),
            event.clientX,
            event.clientY,
        );
    }

    #up(event) {
        const press = this.press;
        if (press === null || event.pointerId !== press.pointerId) {
            return;
        }
        const steps = stepsBetween(
            press.from,
            press.dragging
                ? this.#indexUnder(event, this.#cells())
                : press.from,
        );
        const form = press.cell.querySelector('form[data-graph-drag-form]');
        const commit = press.dragging && steps !== 0 && form !== null;
        this.#end();
        if (commit) {
            form.querySelector('[data-graph-drag-steps]').value = String(steps);
            form.requestSubmit();
        }
    }

    #end() {
        const press = this.press;
        this.press = null;
        if (press === null) {
            return;
        }
        if (press.grip.hasPointerCapture?.(press.pointerId)) {
            press.grip.releasePointerCapture(press.pointerId);
        }
        delete this.element.dataset.dragging;
        press.cell.classList.remove(DRAGGING_CLASS);
        this.#cells().forEach((cell) => cell.classList.remove(DROP_CLASS));
    }
}
