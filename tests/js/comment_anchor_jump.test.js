/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import CommentAnchorController from '../../assets/controllers/comment_anchor_controller.js';
import SheetController from '../../assets/controllers/sheet_controller.js';

let application;
let opened;
let scrolled;
let finishClose;
let dialog;
let order;
const nativeMatches = Element.prototype.matches;

class FakeHighlight extends Set {}

const nextFrame = () =>
    new Promise((resolve) => requestAnimationFrame(() => resolve()));
const settle = async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await nextFrame();
    await nextFrame();
};

beforeEach(() => {
    opened = [];
    scrolled = [];
    order = [];
    window.localStorage.clear();
    window.Highlight = FakeHighlight;
    window.CSS = { highlights: new Map() };
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    window.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    HTMLElement.prototype.showPopover = function () {
        opened.push(this);
        this.dataset.open = 'true';
    };
    HTMLElement.prototype.hidePopover = function () {
        delete this.dataset.open;
    };
    Element.prototype.matches = function (selector) {
        return selector === ':popover-open'
            ? this.dataset.open === 'true'
            : nativeMatches.call(this, selector);
    };
    Element.prototype.scrollIntoView = function () {
        order.push('scroll');
        scrolled.push(this);
    };
    Range.prototype.getClientRects = () => [
        { top: 100, bottom: 120, left: 50, right: 90 },
    ];
    Range.prototype.getBoundingClientRect = () => ({
        top: 100,
        bottom: 120,
        left: 50,
        right: 90,
    });
    Object.defineProperty(document.documentElement, 'clientWidth', {
        configurable: true,
        get: () => 1024,
    });
    application = Application.start();
    application.register('comment-anchor', CommentAnchorController);
    application.register('sheet', SheetController);
});

afterEach(async () => {
    vi.useRealTimers();
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    vi.unstubAllGlobals();
    delete window.Highlight;
    delete window.CSS;
    delete HTMLElement.prototype.showPopover;
    delete HTMLElement.prototype.hidePopover;
    Element.prototype.matches = nativeMatches;
    delete Range.prototype.getClientRects;
    delete Range.prototype.getBoundingClientRect;
    delete Element.prototype.scrollIntoView;
});

function sheetRow(id) {
    return `<button type="button" id="sheet-comment-row-${id}" data-thread-id="comment-thread-${id}"
        data-anchor-status="pending"
        data-action="click->comment-anchor#jumpToRow"></button>`;
}

function card(id, quote) {
    return `<div id="comment-thread-${id}" popover="auto" data-comment-anchor-target="thread"
        data-anchor-quote="${quote}" data-anchor-prefix="" data-anchor-suffix=""
        data-anchor-status="pending" data-anchor-kind="comment"></div>`;
}

async function mount() {
    document.body.innerHTML = `<main class="lp-main"><div data-controller="comment-anchor">
        <div data-comment-anchor-target="block">
            <div data-comment-anchor-target="doc">Alpha beta gamma delta</div>
            <div id="comment-threads">${card('early', 'beta')}${card('lost', 'nowhere')}</div>
        </div>
        <div data-controller="sheet">
            <dialog data-sheet-target="dialog">${sheetRow('early')}${sheetRow('lost')}</dialog>
        </div>
    </div></main>`;
    dialog = document.querySelector('dialog');
    dialog.showModal = () => {
        dialog.open = true;
    };
    dialog.close = () => {
        order.push('close');
        dialog.open = false;
    };
    dialog.getAnimations = () => [];
    dialog.animate = () => {
        const finished = new Promise((resolve) => {
            finishClose = resolve;
        });

        return { finished, cancel() {} };
    };
    await settle();
    dialog.open = true;
}

const rowOf = (id) => document.getElementById(`sheet-comment-row-${id}`);
const flash = () => window.CSS.highlights.get('lp-anchor-flash');

it('waits for the sheet to close, then scrolls to a passage that is already in view', async () => {
    await mount();
    rowOf('early').click();
    await Promise.resolve();

    expect(scrolled).toEqual([]);
    expect(dialog.open).toBe(true);

    finishClose();
    await settle();

    expect(order).toEqual(['close', 'scroll']);
    expect(scrolled).toEqual([
        document.querySelector('[data-comment-anchor-target="doc"]'),
    ]);
    expect(opened).toEqual([]);
});

it('opens the popover of a thread with no passage, once the sheet has closed', async () => {
    await mount();
    rowOf('lost').click();
    await Promise.resolve();

    expect(opened).toEqual([]);

    finishClose();
    await settle();

    expect(dialog.open).toBe(false);
    expect(scrolled).not.toContain(
        document.querySelector('[data-comment-anchor-target="doc"]'),
    );
    expect(opened).toEqual([document.getElementById('comment-thread-lost')]);
});

it('flashes the passage and removes the flash after 1.2 seconds', async () => {
    await mount();
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    rowOf('early').click();
    await Promise.resolve();
    finishClose();
    await vi.advanceTimersByTimeAsync(0);

    expect(flash().size).toBe(1);
    const [range] = [...flash()];
    expect(range.toString()).toBe('beta');

    await vi.advanceTimersByTimeAsync(1199);
    expect(flash().size).toBe(1);

    await vi.advanceTimersByTimeAsync(1);
    expect(flash().size).toBe(0);
});

it('flashes nothing for a thread with no passage', async () => {
    await mount();
    rowOf('lost').click();
    await Promise.resolve();
    finishClose();
    await settle();

    expect(flash().size).toBe(0);
});
