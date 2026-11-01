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

/** How close to the edge of the page the pointer scrolls it, and how fast. */
const EDGE_BAND = 56;
const EDGE_STEP = 14;

const DRAGGING_CLASS = 'lp-dashboard-grid__cell--dragging';
const PLACEHOLDER_CLASS = 'lp-dashboard-grid__placeholder';
const LIFTED_CLASS = 'lp-dashboard-grid__lifted';

const contains = (rectangle, x, y) =>
    x >= rectangle.left &&
    x <= rectangle.right &&
    y >= rectangle.top &&
    y <= rectangle.bottom;

/**
 * Reorders the tiles of the Dashboard. A person drags a tile by its grip. A
 * lifted copy follows the pointer, the tile stays faded in its slot, and a
 * dashed slot marks where a release lands it. The drop moves the tile at once
 * and posts its move form with the step count. The server answers with a
 * redirect, so the page shows the saved order, and a refused move puts the tile
 * back. The Move up and Move down menu items are the keyboard way. The grid
 * carries `data-dragging` during a drag, so the live update waits.
 */
export default class extends Controller {
    connect() {
        this.press = null;
        this.moving = false;
        this.onDown = (event) => this.#down(event);
        this.onMove = (event) => this.#move(event);
        this.onUp = (event) => this.#up(event);
        this.onCancel = () => this.#end();
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.#end();
            }
        };
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
            this.moving ||
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
            lifted: null,
            placeholder: null,
            scroller: null,
            scrollFrame: null,
            lastEvent: null,
            originNext: null,
            offsetX: 0,
            offsetY: 0,
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
            this.#begin(press);
        }
        press.lifted.style.left = `${event.clientX - press.offsetX}px`;
        press.lifted.style.top = `${event.clientY - press.offsetY}px`;
        press.lastEvent = event;
        this.#mark(press, event);
        this.#followEdge(press);
    }

    /** The page scrolls while the pointer rests near its edge, so a slot below the fold comes into view. */
    #followEdge(press) {
        if (press.scrollFrame !== null || this.#edgeStep(press) === 0) {
            return;
        }
        const tick = () => {
            press.scrollFrame = null;
            if (this.press !== press) {
                return;
            }
            const step = this.#edgeStep(press);
            const before = press.scroller.scrollTop;
            press.scroller.scrollTop = before + step;
            if (press.scroller.scrollTop === before) {
                return;
            }
            this.#mark(press, press.lastEvent);
            press.scrollFrame = requestAnimationFrame(tick);
        };
        press.scrollFrame = requestAnimationFrame(tick);
    }

    #edgeStep(press) {
        const page = document.scrollingElement;
        const box =
            press.scroller === page
                ? { top: 0, bottom: window.innerHeight }
                : press.scroller.getBoundingClientRect();
        const pointerY = press.lastEvent.clientY;
        if (pointerY < box.top + EDGE_BAND) {
            return -EDGE_STEP;
        }

        return pointerY > box.bottom - EDGE_BAND ? EDGE_STEP : 0;
    }

    #scrollerOf(element) {
        for (
            let parent = element.parentElement;
            parent !== null;
            parent = parent.parentElement
        ) {
            if (/auto|scroll/.test(getComputedStyle(parent).overflowY)) {
                return parent;
            }
        }

        return document.scrollingElement;
    }

    #begin(press) {
        const box = press.cell.getBoundingClientRect();
        press.dragging = true;
        press.scroller = this.#scrollerOf(this.element);
        press.originNext = press.cell.nextElementSibling;
        press.offsetX = press.x - box.left;
        press.offsetY = press.y - box.top;

        press.placeholder = document.createElement('div');
        press.placeholder.className = `${press.cell.className} ${PLACEHOLDER_CLASS}`;
        press.placeholder.style.height = `${box.height}px`;

        press.lifted = this.#liftedCopy(press.cell);
        press.lifted.style.width = `${box.width}px`;
        press.lifted.style.height = `${box.height}px`;
        this.element.append(press.lifted);

        press.cell.classList.add(DRAGGING_CLASS);
        this.element.dataset.dragging = '';
        // Turbo reads this on an ancestor, so no link under the pointer prefetches.
        this.element.dataset.turboPrefetch = 'false';
        window.addEventListener('keydown', this.onKeydown);
    }

    /**
     * A copy of the tile that follows the pointer. It carries no id, data
     * attribute or form, so no controller or query mistakes it for the tile.
     */
    #liftedCopy(cell) {
        const lifted = document.createElement('div');
        lifted.className = LIFTED_CLASS;
        lifted.inert = true;
        lifted.setAttribute('aria-hidden', 'true');
        lifted.append(
            ...[...cell.children].map((child) => child.cloneNode(true)),
        );
        lifted
            .querySelectorAll('form, template')
            .forEach((element) => element.remove());
        lifted.querySelectorAll('*').forEach((element) => {
            for (const { name } of Array.from(element.attributes)) {
                if (name === 'id' || name.startsWith('data-')) {
                    element.removeAttribute(name);
                }
            }
        });

        return lifted;
    }

    /**
     * Puts the dashed slot where a release at this point lands the tile. The
     * slot pushes the other tiles along, so a pointer that rests on the slot
     * keeps the place it marks.
     */
    #mark(press, event) {
        if (
            press.placeholder.isConnected &&
            contains(
                press.placeholder.getBoundingClientRect(),
                event.clientX,
                event.clientY,
            )
        ) {
            return;
        }
        const cells = this.#cells();
        press.to = indexAtPoint(
            cells.map((cell) => cell.getBoundingClientRect()),
            event.clientX,
            event.clientY,
        );
        if (press.to === press.from) {
            press.placeholder.remove();
        } else if (press.to > press.from) {
            cells[press.to].after(press.placeholder);
        } else {
            cells[press.to].before(press.placeholder);
        }
    }

    #up(event) {
        const press = this.press;
        if (press === null || event.pointerId !== press.pointerId) {
            return;
        }
        if (press.dragging) {
            this.#mark(press, event);
        }
        const steps = stepsBetween(press.from, press.to);
        const form = press.cell.querySelector('form[data-graph-drag-form]');
        const commit = press.dragging && steps !== 0 && form !== null;
        if (commit) {
            press.placeholder.replaceWith(press.cell);
        }
        const { cell, originNext } = press;
        this.#end();
        if (commit) {
            this.#submit(form, steps, cell, originNext);
        }
    }

    #submit(form, steps, cell, originNext) {
        this.moving = true;
        form.addEventListener(
            'turbo:submit-end',
            (event) => {
                this.moving = false;
                if (
                    event.detail?.success === false &&
                    cell.isConnected &&
                    originNext?.isConnected
                ) {
                    originNext.before(cell);
                }
            },
            { once: true },
        );
        form.querySelector('[data-graph-drag-steps]').value = String(steps);
        form.requestSubmit();
    }

    #end() {
        const press = this.press;
        this.press = null;
        if (press === null) {
            return;
        }
        window.removeEventListener('keydown', this.onKeydown);
        if (press.scrollFrame !== null) {
            cancelAnimationFrame(press.scrollFrame);
        }
        if (press.grip.hasPointerCapture?.(press.pointerId)) {
            press.grip.releasePointerCapture(press.pointerId);
        }
        delete this.element.dataset.dragging;
        delete this.element.dataset.turboPrefetch;
        press.cell.classList.remove(DRAGGING_CLASS);
        press.lifted?.remove();
        press.placeholder?.remove();
    }
}
