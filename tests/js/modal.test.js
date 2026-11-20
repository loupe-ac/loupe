/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ModalController from '../../assets/controllers/modal_controller.js';

let application;
let controller;
let dialog;
let completions;

beforeEach(async () => {
    completions = [];
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    document.body.innerHTML =
        '<div data-controller="modal"><dialog data-modal-target="dialog"><textarea>Keep my draft.</textarea></dialog></div>';
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: new Promise((resolve) => completions.push(resolve)),
        cancel: vi.fn(),
    }));
    application = Application.start();
    application.register('modal', ModalController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.body.firstElementChild,
        'modal',
    );
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
});

async function finish(index) {
    completions[index]();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

it('does not let an earlier close dismiss a reopened dialog', async () => {
    controller.open();
    controller.close();
    controller.open();
    await finish(1);
    expect(dialog.open).toBe(true);
    expect(dialog.close).not.toHaveBeenCalled();
    expect(dialog.querySelector('textarea').value).toBe('Keep my draft.');
});

it('closes once when dismissal repeats during the animation', async () => {
    controller.open();
    controller.close();
    controller.close();
    await finish(1);
    expect(dialog.open).toBe(true);
    expect(dialog.classList.contains('is-closing')).toBe(true);
    await finish(2);
    expect(dialog.close).toHaveBeenCalledOnce();
});

it('ignores a pending close after the controller disconnects', async () => {
    controller.open();
    controller.close();
    controller.disconnect();
    await finish(1);
    expect(dialog.close).not.toHaveBeenCalled();
});

it('does not animate a dialog that is already closed', () => {
    controller.close();
    expect(dialog.animate).not.toHaveBeenCalled();
});

it('slides an opted-in drawer horizontally', () => {
    controller.drawerValue = true;
    controller.open();
    expect(dialog.animate.mock.calls[0][0]).toEqual([
        { transform: 'translateX(100%)' },
        { transform: 'translateX(0)' },
    ]);
    controller.close();
    expect(dialog.animate.mock.calls[1][0]).toEqual([
        { transform: 'translateX(0)' },
        { transform: 'translateX(100%)' },
    ]);
});

it('times a drawer slide at 200ms with the ease curve both ways', () => {
    controller.drawerValue = true;
    controller.open();
    controller.close();
    expect(dialog.animate).toHaveBeenCalledTimes(2);
    for (const call of dialog.animate.mock.calls) {
        expect(call[1]).toMatchObject({ duration: 200, easing: 'ease' });
    }
});

it('returns focus to the trigger once the close animation ends', async () => {
    const trigger = document.createElement('button');
    document.body.append(trigger);
    trigger.focus();
    controller.open();
    dialog.querySelector('textarea').focus();
    controller.close();
    await finish(1);
    expect(document.activeElement).toBe(trigger);
});

it('suppresses drawer motion when reduced motion is requested', () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    controller.drawerValue = true;
    controller.open();
    controller.close();
    expect(dialog.animate).not.toHaveBeenCalled();
});

const DROP_FROM_ABOVE = [
    { opacity: 0, transform: 'translateY(-16px)' },
    { opacity: 1, transform: 'translateY(0)' },
];
const SLIDE_UP = [
    { transform: 'translateY(100%)' },
    { transform: 'translateY(0)' },
];

function setWidth(pixels) {
    vi.stubGlobal('matchMedia', (query) => {
        const edge = /width < ([\d.]+)rem/.exec(query);
        return { matches: edge ? pixels < Number(edge[1]) * 16 : false };
    });
}

it.each([
    [820, DROP_FROM_ABOVE],
    [1440, DROP_FROM_ABOVE],
    [390, SLIDE_UP],
])('opens a plain dialog at %ipx with its own keyframes', (width, expected) => {
    setWidth(width);
    controller.open();
    expect(dialog.animate.mock.calls[0][0]).toEqual(expected);
    expect(dialog.animate.mock.calls[0][1]).toEqual({
        duration: 220,
        easing: 'ease-out',
    });
});

it('closes a plain dialog at 820px by dropping it upward over 180ms', () => {
    setWidth(820);
    controller.open();
    controller.close();
    expect(dialog.animate.mock.calls[1][0]).toEqual([
        { opacity: 1, transform: 'translateY(0)' },
        { opacity: 0, transform: 'translateY(-16px)' },
    ]);
    expect(dialog.animate.mock.calls[1][1]).toEqual({
        duration: 180,
        easing: 'ease-in',
        fill: 'forwards',
    });
});

it('slides a drawer sideways at 390px and 820px alike', () => {
    controller.drawerValue = true;
    for (const width of [390, 820]) {
        setWidth(width);
        dialog.open = false;
        controller.open();
        expect(dialog.animate.mock.lastCall[0]).toEqual([
            { transform: 'translateX(100%)' },
            { transform: 'translateX(0)' },
        ]);
    }
});

function morphAttribute(attributeName, mutationType) {
    const event = new CustomEvent('turbo:before-morph-attribute', {
        bubbles: true,
        cancelable: true,
        detail: { attributeName, mutationType },
    });
    dialog.dispatchEvent(event);

    return event;
}

it('keeps the open attribute when a morph removes it, then closes the dialog', async () => {
    controller.open();
    const event = morphAttribute('open', 'remove');
    expect(event.defaultPrevented).toBe(true);
    await finish(1);
    expect(dialog.close).toHaveBeenCalledOnce();
});

it('keeps the dialog open when a morph removes open and the server asked for a reopen', async () => {
    controller.reopenValue = true;
    controller.open();
    const event = morphAttribute('open', 'remove');
    expect(event.defaultPrevented).toBe(true);
    await Promise.resolve();
    expect(dialog.close).not.toHaveBeenCalled();
    expect(dialog.open).toBe(true);
});

it('leaves other morphed attributes alone', () => {
    controller.open();
    expect(morphAttribute('class', 'remove').defaultPrevented).toBe(false);
    expect(morphAttribute('open', 'update').defaultPrevented).toBe(false);
    expect(dialog.close).not.toHaveBeenCalled();
});

it('stops listening for morphs once the dialog target leaves', () => {
    controller.dialogTargetDisconnected(dialog);
    expect(morphAttribute('open', 'remove').defaultPrevented).toBe(false);
});

it('opens the dialog when a morph turns the reopen value on', async () => {
    document.body.firstElementChild.setAttribute(
        'data-modal-reopen-value',
        'true',
    );
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(dialog.showModal).toHaveBeenCalledOnce();
    expect(dialog.open).toBe(true);
});

it('does not open again when the dialog is already open as the reopen value turns on', async () => {
    controller.open();
    dialog.showModal.mockClear();
    document.body.firstElementChild.setAttribute(
        'data-modal-reopen-value',
        'true',
    );
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(dialog.showModal).not.toHaveBeenCalled();
});

it('closes the dialog before it renders a stream that marks a target inside it', async () => {
    dialog.insertAdjacentHTML('beforeend', '<form id="inside"></form>');
    controller.open();
    const stream = document.createElement('turbo-stream');
    stream.setAttribute('action', 'replace');
    stream.setAttribute('target', 'inside');
    stream.setAttribute('data-closes-dialog', '');
    const render = vi.fn();
    const event = new CustomEvent('turbo:before-stream-render', {
        detail: { newStream: stream, render },
    });
    document.dispatchEvent(event);
    event.detail.render(stream);
    expect(render).not.toHaveBeenCalled();
    await finish(1);
    expect(dialog.close).toHaveBeenCalledOnce();
    expect(render).toHaveBeenCalledOnce();
});

it('leaves a stream with no mark alone when its target is inside the dialog', () => {
    dialog.insertAdjacentHTML('beforeend', '<form id="inside"></form>');
    controller.open();
    const stream = document.createElement('turbo-stream');
    stream.setAttribute('action', 'replace');
    stream.setAttribute('target', 'inside');
    const render = vi.fn();
    const event = new CustomEvent('turbo:before-stream-render', {
        detail: { newStream: stream, render },
    });
    document.dispatchEvent(event);
    expect(event.detail.render).toBe(render);
});
