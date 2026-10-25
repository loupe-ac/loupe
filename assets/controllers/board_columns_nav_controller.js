import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

const SETTLE_MILLISECONDS = 700;
const SWIPE_MIN_DISTANCE = 48;
const SWIPE_DOMINANCE = 2;
const isPhone = () => window.innerWidth < 640;

/**
 * The column tabs of the phone board. The element holds the tab strip (the
 * `tabs` target) and the column strip. On a phone the strip scrolls only up and
 * down, and a clear sideways swipe, a tab or an arrow steps the board one
 * column. The column nearest the sideways scroll position is the current
 * column: its tab is selected. The columns of the first lane name the tabs and
 * give the offsets, so a collapsed lane changes nothing.
 */
export default class extends Controller {
    static targets = ['tabs'];

    connect() {
        this.current = 0;
        this.settling = null;
        this.touchStart = null;
        this.onStep = (event) => this.step(event.detail.direction);
        this.onStructure = () => this.#bind();
        this.onScrollEnd = () => this.#scrollEnded();
        this.onTouchStart = (event) => this.#touchStarted(event);
        this.onTouchEnd = (event) => this.#touchEnded(event);
        this.onTouchCancel = () => {
            this.touchStart = null;
        };
        this.element.addEventListener('overflow-cue:step', this.onStep);
        document.addEventListener('board:structure-changed', this.onStructure);
        this.#bind();
    }

    disconnect() {
        this.element.removeEventListener('overflow-cue:step', this.onStep);
        document.removeEventListener(
            'board:structure-changed',
            this.onStructure,
        );
        this.#unbind();
        clearTimeout(this.settleTimer);
    }

    select(event) {
        this.reveal(event.currentTarget.dataset.columnId);
    }

    /** Scrolls the board to the column with this id, and does nothing for an id it has no column for. */
    reveal(columnId) {
        const index = this.#columns().findIndex(
            (column) => column.dataset.columnId === columnId,
        );
        if (index >= 0) {
            this.#goTo(index);
        }
    }

    step(direction) {
        const last = this.#columns().length - 1;
        if (last < 0) {
            return;
        }
        const from = this.settling ?? this.current;
        this.#goTo(Math.min(Math.max(from + direction, 0), last));
    }

    #strip() {
        return this.element.querySelector(
            ':scope > [data-board-view-target="board"]',
        );
    }

    #columns() {
        const strip = this.#strip();
        if (!strip) {
            return [];
        }

        return [
            ...strip.querySelectorAll(
                ':scope > .lp-board__column, :scope > .lp-board-lanes > .lp-board-lane:first-child .lp-board__column',
            ),
        ];
    }

    /** The sideways offset of each column, read from the first lane so it does not depend on the scroll position. */
    #offsets() {
        const columns = this.#columns();
        if (0 === columns.length) {
            return [];
        }
        const origin = columns[0].getBoundingClientRect().left;

        return columns.map(
            (column) => column.getBoundingClientRect().left - origin,
        );
    }

    #bind() {
        this.#unbind();
        const strip = this.#strip();
        if (!strip) {
            return;
        }
        this.strip = strip;
        this.#syncTabs();
        this.counts = new MutationObserver(() => this.#syncCounts());
        this.counts.observe(strip, {
            subtree: true,
            childList: true,
            characterData: true,
        });
        strip.addEventListener('scrollend', this.onScrollEnd);
        strip.addEventListener('touchstart', this.onTouchStart, {
            passive: true,
        });
        strip.addEventListener('touchend', this.onTouchEnd, { passive: true });
        strip.addEventListener('touchcancel', this.onTouchCancel, {
            passive: true,
        });
        this.#show(Math.min(this.current, this.#columns().length - 1));
    }

    #unbind() {
        this.counts?.disconnect();
        this.strip?.removeEventListener('scrollend', this.onScrollEnd);
        this.strip?.removeEventListener('touchstart', this.onTouchStart);
        this.strip?.removeEventListener('touchend', this.onTouchEnd);
        this.strip?.removeEventListener('touchcancel', this.onTouchCancel);
        this.counts = null;
        this.strip = null;
    }

    #touchStarted(event) {
        const point = event.touches.length > 1 ? null : event.touches[0];
        this.touchStart = point ? { x: point.clientX, y: point.clientY } : null;
    }

    #touchEnded(event) {
        const start = this.touchStart;
        this.touchStart = null;
        const point = event.changedTouches[0];
        if (!start || !point || !isPhone()) {
            return;
        }
        const across = point.clientX - start.x;
        const down = point.clientY - start.y;
        if (
            Math.abs(across) > SWIPE_MIN_DISTANCE &&
            Math.abs(across) > SWIPE_DOMINANCE * Math.abs(down)
        ) {
            this.step(across < 0 ? 1 : -1);
        }
    }

    #scrollEnded() {
        if (null === this.settling) {
            this.#pick();
        } else {
            this.#settled();
        }
    }

    #pick() {
        if (!this.strip) {
            return;
        }
        let best = -1;
        let bestDistance = Infinity;
        this.#offsets().forEach((offset, index) => {
            const distance = Math.abs(offset - this.strip.scrollLeft);
            if (distance < bestDistance) {
                best = index;
                bestDistance = distance;
            }
        });
        if (best >= 0) {
            this.#show(best);
        }
    }

    #goTo(index) {
        const left = this.#offsets()[index];
        if (undefined === left || !this.strip) {
            return;
        }
        this.settling = index;
        this.#show(index);
        clearTimeout(this.settleTimer);
        this.settleTimer = setTimeout(
            () => this.#settled(),
            SETTLE_MILLISECONDS,
        );
        this.strip.scrollTo({
            left,
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
    }

    #settled() {
        clearTimeout(this.settleTimer);
        if (null === this.settling) {
            return;
        }
        this.settling = null;
        this.#pick();
    }

    #show(index) {
        this.current = Math.max(index, 0);
        const columns = this.#columns();
        const id = columns[this.current]?.dataset.columnId;
        for (const tab of this.#tabs()) {
            tab.setAttribute(
                'aria-selected',
                String(tab.dataset.columnId === id),
            );
        }
        const prev = this.#arrow('prev');
        const next = this.#arrow('next');
        const focused = document.activeElement;
        if (prev) {
            prev.hidden = this.current <= 0;
        }
        if (next) {
            next.hidden = this.current >= columns.length - 1;
        }
        if (
            (prev?.hidden && focused === prev) ||
            (next?.hidden && focused === next)
        ) {
            this.#tabs()
                .find((tab) => 'true' === tab.getAttribute('aria-selected'))
                ?.focus();
        }
    }

    #arrow(side) {
        return this.hasTabsTarget
            ? this.tabsTarget.querySelector(`.lp-overflow-cue__arrow--${side}`)
            : null;
    }

    #tabs() {
        return this.hasTabsTarget
            ? [...this.tabsTarget.querySelectorAll('.lp-tabs__tab')]
            : [];
    }

    /** The tabs follow the column heads, so a column added or renamed live reaches them. */
    #syncTabs() {
        if (!this.hasTabsTarget) {
            return;
        }
        const strip = this.tabsTarget.querySelector('.lp-tabs');
        const columns = this.#columns();
        const wanted = columns.map(
            (column) => `${column.dataset.columnId}|${this.#label(column)}`,
        );
        const have = this.#tabs().map(
            (tab) =>
                `${tab.dataset.columnId}|${tab.querySelector('[data-tab-label]')?.textContent}`,
        );
        if (strip && wanted.join('\n') !== have.join('\n')) {
            strip.replaceChildren(
                ...columns.map((column) => this.#tab(column)),
            );
        }
        this.#syncCounts();
    }

    #tab(column) {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'lp-tabs__tab';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', 'false');
        tab.dataset.columnId = column.dataset.columnId;
        tab.dataset.action = 'board-columns-nav#select';
        const label = document.createElement('span');
        label.dataset.tabLabel = '';
        label.textContent = this.#label(column);
        const count = document.createElement('span');
        count.className = 'lp-tabs__count';
        count.dataset.tabCount = '';
        tab.append(label, count);

        return tab;
    }

    #label(column) {
        return (
            column
                .querySelector('.lp-board__column-title')
                ?.textContent.trim() ?? ''
        );
    }

    #syncCounts() {
        const strip = this.#strip();
        if (!strip) {
            return;
        }
        for (const tab of this.#tabs()) {
            let total = 0;
            strip
                .querySelectorAll(
                    `.lp-board__column[data-column-id="${CSS.escape(tab.dataset.columnId)}"] .lp-board__column-count`,
                )
                .forEach((count) => {
                    total += Number.parseInt(count.textContent, 10) || 0;
                });
            const target = tab.querySelector('[data-tab-count]');
            if (target && target.textContent !== String(total)) {
                target.textContent = String(total);
            }
        }
    }
}
