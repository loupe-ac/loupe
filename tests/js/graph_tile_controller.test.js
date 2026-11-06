/** @vitest-environment jsdom */
import { Application, Controller } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import GraphTileController from '../../assets/controllers/graph_tile_controller.js';

const opened = vi.fn();
const removeOpened = vi.fn();
let application;

class FakeModalController extends Controller {
    open() {
        (this.element.hasAttribute('data-share-dialog')
            ? opened
            : removeOpened)();
    }
}

beforeEach(async () => {
    opened.mockClear();
    removeOpened.mockClear();
    application = Application.start();
    application.register('graph-tile', GraphTileController);
    application.register('modal', FakeModalController);
    document.body.innerHTML = `<article data-controller="modal graph-tile">
        <button type="button" data-popover-target="trigger" aria-expanded="true"></button>
        <button type="button" data-action="click->graph-tile#share">Share</button>
        <div data-controller="modal" data-share-dialog></div>
    </article>`;
    await Promise.resolve();
});

afterEach(() => {
    document.body.replaceChildren();
    application.stop();
});

it('opens the share dialog through its own modal controller', () => {
    document.querySelector('[data-action]').click();

    expect(opened).toHaveBeenCalledTimes(1);
    expect(removeOpened).not.toHaveBeenCalled();
});

it('presses the menu trigger so the menu closes', () => {
    const trigger = document.querySelector('[data-popover-target="trigger"]');
    const pressed = vi.fn();
    trigger.addEventListener('click', pressed);

    document.querySelector('[data-action]').click();

    expect(pressed).toHaveBeenCalledTimes(1);
});
