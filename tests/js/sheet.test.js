/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import SheetController from '../../assets/controllers/sheet_controller.js';

const SLIDE_UP = [
    { transform: 'translateY(100%)' },
    { transform: 'translateY(0)' },
];
const SLIDE_DOWN = [
    { transform: 'translateY(0)' },
    { transform: 'translateY(100%)' },
];

let application;
let controller;
let dialog;

function setWidth(pixels) {
    vi.stubGlobal('matchMedia', (query) => {
        const edge = /width < ([\d.]+)rem/.exec(query);
        return { matches: edge ? pixels < Number(edge[1]) * 16 : false };
    });
}

async function mount() {
    document.body.innerHTML = `<div data-controller="sheet">
        <dialog data-sheet-target="dialog">
            <button data-sheet-target="back" data-action="click->sheet#back" hidden>Back</button>
            <div data-sheet-view="menu">
                <button id="to-versions" data-action="click->sheet#show" data-sheet-view-param="versions">Versions</button>
            </div>
            <div data-sheet-view="versions">
                <button id="to-references" data-action="click->sheet#show" data-sheet-view-param="references">References</button>
            </div>
            <div data-sheet-view="references"></div>
        </dialog>
    </div>`;
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: new Promise(() => {}),
        cancel: vi.fn(),
    }));
    application = Application.start();
    application.register('sheet', SheetController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.body.firstElementChild,
        'sheet',
    );
}

function visibleViews() {
    return [...dialog.querySelectorAll('[data-sheet-view]')]
        .filter((view) => !view.hidden)
        .map((view) => view.dataset.sheetView);
}

beforeEach(() => {
    setWidth(390);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
});

it.each([390, 820, 1440])(
    'slides up from the bottom at %ipx',
    async (width) => {
        setWidth(width);
        await mount();
        controller.open();
        expect(dialog.showModal).toHaveBeenCalledOnce();
        expect(dialog.animate.mock.calls[0][0]).toEqual(SLIDE_UP);
        controller.close();
        expect(dialog.animate.mock.calls[1][0]).toEqual(SLIDE_DOWN);
    },
);

it('skips the slide when the reader asks for reduced motion', async () => {
    await mount();
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    controller.open();
    expect(dialog.showModal).toHaveBeenCalledOnce();
    expect(dialog.animate).not.toHaveBeenCalled();
});

it('shows only the first view and no back button at the start', async () => {
    await mount();
    expect(visibleViews()).toEqual(['menu']);
    expect(dialog.querySelector('[data-sheet-target="back"]').hidden).toBe(
        true,
    );
});

it('drills down one view at a time and shows the back button', async () => {
    await mount();
    document.getElementById('to-versions').click();
    expect(visibleViews()).toEqual(['versions']);
    expect(dialog.querySelector('[data-sheet-target="back"]').hidden).toBe(
        false,
    );
    document.getElementById('to-references').click();
    expect(visibleViews()).toEqual(['references']);
});

it('goes back one view at a time and hides the back button on the first', async () => {
    await mount();
    document.getElementById('to-versions').click();
    document.getElementById('to-references').click();
    const back = dialog.querySelector('[data-sheet-target="back"]');
    back.click();
    expect(visibleViews()).toEqual(['versions']);
    expect(back.hidden).toBe(false);
    back.click();
    expect(visibleViews()).toEqual(['menu']);
    expect(back.hidden).toBe(true);
});

it('ignores a view name it does not hold', async () => {
    await mount();
    controller.showView('missing');
    expect(visibleViews()).toEqual(['menu']);
});

it('starts from the first view each time it opens', async () => {
    await mount();
    document.getElementById('to-versions').click();
    controller.open();
    expect(visibleViews()).toEqual(['menu']);
    expect(dialog.querySelector('[data-sheet-target="back"]').hidden).toBe(
        true,
    );
});
