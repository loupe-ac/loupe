import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

const RATIO_STEPS = [0, 0.25, 0.5, 0.75, 1];
const SETTLE_MILLISECONDS = 700;

/**
 * The column tabs of the phone board. The element holds the tab strip (the
 * `tabs` target) and the column strip, and the strip scrolls one column per
 * swipe. The column most in view is the current column: its tab is selected,
 * and the arrows of the tab strip step the board one column. A board with epic
 * lanes reads its columns from the first lane.
 */
export default class extends Controller {
    static targets = ['tabs'];

    connect() {
        this.current = 0;
        this.settling = null;
        this.ratios = new Map();
        this.onStep = (event) => this.step(event.detail.direction);
        this.onStructure = () => this.#bind();
        this.onScrollEnd = () => this.#settled();
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
        const index = this.#columns().findIndex(
            (column) =>
                column.dataset.columnId ===
                event.currentTarget.dataset.columnId,
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

    #bind() {
        this.#unbind();
        const strip = this.#strip();
        if (!strip) {
            return;
        }
        this.strip = strip;
        this.ratios.clear();
        this.#syncTabs();
        this.intersections = new IntersectionObserver(
            (entries) => this.#observed(entries),
            { root: strip, threshold: RATIO_STEPS },
        );
        for (const column of this.#columns()) {
            this.intersections.observe(column);
        }
        this.counts = new MutationObserver(() => this.#syncCounts());
        this.counts.observe(strip, {
            subtree: true,
            childList: true,
            characterData: true,
        });
        strip.addEventListener('scrollend', this.onScrollEnd);
        this.#show(Math.min(this.current, this.#columns().length - 1));
    }

    #unbind() {
        this.intersections?.disconnect();
        this.counts?.disconnect();
        this.strip?.removeEventListener('scrollend', this.onScrollEnd);
        this.intersections = null;
        this.counts = null;
        this.strip = null;
    }

    #observed(entries) {
        for (const entry of entries) {
            this.ratios.set(entry.target, entry.intersectionRatio);
        }
        if (null !== this.settling) {
            return;
        }
        this.#pick();
    }

    #pick() {
        let best = -1;
        let bestRatio = 0;
        this.#columns().forEach((column, index) => {
            const ratio = this.ratios.get(column) ?? 0;
            if (ratio > bestRatio) {
                best = index;
                bestRatio = ratio;
            }
        });
        if (best >= 0) {
            this.#show(best);
        }
    }

    #goTo(index) {
        const column = this.#columns()[index];
        if (!column) {
            return;
        }
        this.settling = index;
        this.#show(index);
        clearTimeout(this.settleTimer);
        this.settleTimer = setTimeout(
            () => this.#settled(),
            SETTLE_MILLISECONDS,
        );
        column.scrollIntoView({
            inline: 'start',
            block: 'nearest',
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
