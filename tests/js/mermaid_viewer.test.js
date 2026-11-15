/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ViewerController from '../../assets/controllers/mermaid_viewer_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const page = `<div id="viewer" data-controller="mermaid-viewer" data-action="mermaid:expand@window->mermaid-viewer#show">
    <dialog data-mermaid-viewer-target="dialog" data-action="cancel->mermaid-viewer#close">
        <button type="button" data-mermaid-viewer-target="zoomOut" data-action="mermaid-viewer#zoomOut">-</button>
        <output data-mermaid-viewer-target="output"></output>
        <button type="button" data-mermaid-viewer-target="zoomIn" data-action="mermaid-viewer#zoomIn">+</button>
        <button type="button" id="fit" data-action="mermaid-viewer#fit">fit</button>
        <div data-mermaid-viewer-target="stage"></div>
    </dialog>
</div>
<div id="host"><button id="figure" tabindex="0">figure</button><button id="expand">expand</button>
<svg id="source" viewBox="0 0 1000 500" style="max-width: 1000px"><text>x</text></svg></div>`;

const stageRect = { width: 800, height: 400, left: 0, top: 0 };
const dialog = () => document.querySelector('dialog');
const stage = () =>
    document.querySelector('[data-mermaid-viewer-target="stage"]');
const svg = () => stage().shadowRoot.querySelector('svg');
const output = () => document.querySelector('output');

function expandFrom(opener) {
    const figure = document.getElementById('figure');
    opener.focus();
    window.dispatchEvent(
        new CustomEvent('mermaid:expand', {
            detail: { svg: document.getElementById('source'), figure },
        }),
    );
}

beforeEach(async () => {
    HTMLDialogElement.prototype.showModal = function () {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function () {
        this.open = false;
    };
    Element.prototype.animate = () => ({
        finished: Promise.resolve(),
        cancel() {},
    });
    Element.prototype.getAnimations = () => [];
    globalThis.ResizeObserver = class {
        observe() {}
        disconnect() {}
    };
    window.matchMedia = () => ({ matches: false });
    document.body.innerHTML = page;
    stage().getBoundingClientRect = () => stageRect;
    application = Application.start();
    application.register('mermaid-viewer', ViewerController);
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.restoreAllMocks();
});

it('copies the drawn svg into the stage and fits it', () => {
    expandFrom(document.getElementById('figure'));

    expect(dialog().open).toBe(true);
    expect(svg()).not.toBeNull();
    expect(svg().hasAttribute('style')).toBe(false);
    expect(svg().getAttribute('width')).toBe('800');
    expect(svg().getAttribute('viewBox')).toBe('0 0 1000 500');
    expect(output().textContent).toBe('80%');
    expect(document.getElementById('source').hasAttribute('style')).toBe(true);
});

it('zooms with the buttons and the keys, and fits again', () => {
    expandFrom(document.getElementById('figure'));

    dialog().dispatchEvent(
        new KeyboardEvent('keydown', { key: '+', bubbles: true }),
    );
    expect(output().textContent).toBe('100%');
    dialog().dispatchEvent(
        new KeyboardEvent('keydown', { key: '-', bubbles: true }),
    );
    expect(output().textContent).toBe('80%');
    dialog().dispatchEvent(
        new KeyboardEvent('keydown', { key: '+', bubbles: true }),
    );
    document.getElementById('fit').click();
    expect(output().textContent).toBe('80%');
    dialog().dispatchEvent(
        new KeyboardEvent('keydown', { key: '0', bubbles: true }),
    );
    expect(output().textContent).toBe('80%');
});

it('moves with the arrow keys', () => {
    expandFrom(document.getElementById('figure'));
    const before = svg().getAttribute('viewBox');

    dialog().dispatchEvent(
        new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
    );

    expect(svg().getAttribute('viewBox')).not.toBe(before);
});

it('disables zoom in at 400% and zoom out at the lowest zoom', () => {
    expandFrom(document.getElementById('figure'));
    const zoomIn = document.querySelector(
        '[data-mermaid-viewer-target="zoomIn"]',
    );
    const zoomOut = document.querySelector(
        '[data-mermaid-viewer-target="zoomOut"]',
    );

    for (let step = 0; step < 12; step += 1) zoomIn.click();
    expect(output().textContent).toBe('400%');
    expect(zoomIn.disabled).toBe(true);
    for (let step = 0; step < 20; step += 1) zoomOut.click();
    expect(output().textContent).toBe('25%');
    expect(zoomOut.disabled).toBe(true);
});

it('lets a very large diagram zoom out to its opening fit', () => {
    const big = document.getElementById('source');
    big.setAttribute('viewBox', '0 0 8000 4000');
    expandFrom(document.getElementById('figure'));

    expect(output().textContent).toBe('10%');
    document.querySelector('[data-mermaid-viewer-target="zoomIn"]').click();
    document.querySelector('[data-mermaid-viewer-target="zoomOut"]').click();
    expect(output().textContent).toBe('10%');
    expect(
        document.querySelector('[data-mermaid-viewer-target="zoomOut"]')
            .disabled,
    ).toBe(true);
});

it('returns focus to the figure after an open from the figure and from Expand', async () => {
    const figure = document.getElementById('figure');
    const controller = application.getControllerForElementAndIdentifier(
        document.getElementById('viewer'),
        'mermaid-viewer',
    );

    for (const opener of [figure, document.getElementById('expand')]) {
        expandFrom(opener);
        await controller.close();
        expect(dialog().open).toBe(false);
        expect(document.activeElement).toBe(figure);
        expect(svg()).toBeNull();
    }
});

it('zooms around the pointer on a gesture pinch, with no wheel event', () => {
    expandFrom(document.getElementById('figure'));
    const gesture = (type, scale) => {
        const event = new Event(type, { bubbles: true, cancelable: true });
        Object.assign(event, { scale, clientX: 400, clientY: 200 });
        dialog().dispatchEvent(event);
        return event;
    };

    expect(gesture('gesturestart', 1).defaultPrevented).toBe(true);
    gesture('gesturechange', 1.25);
    expect(output().textContent).toBe('100%');
    gesture('gesturechange', 1.5);
    expect(output().textContent).toBe('120%');
    gesture('gesturestart', 1);
    gesture('gesturechange', 0.5);
    expect(output().textContent).toBe('60%');
});
