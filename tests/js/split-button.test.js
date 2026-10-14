/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import SplitButtonController from '../../assets/controllers/split_button_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const press = (target, key) => {
    const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
    });
    target.dispatchEvent(event);

    return event;
};

beforeEach(async () => {
    document.body.innerHTML = `<span data-controller="split-button">
        <button type="submit" id="main">Resume the run</button>
        <button type="button" id="toggle" aria-expanded="false" data-split-button-target="trigger" data-action="click->split-button#toggle">More</button>
        <div role="menu" data-split-button-target="panel" hidden>
            <a role="menuitem" id="first" href="/a">Open</a>
            <div role="menuitem" id="disabled" aria-disabled="true">Start</div>
            <button role="menuitem" id="last" type="submit">Dismiss</button>
        </div>
    </span><button id="outside">Outside</button>`;
    application = Application.start();
    application.register('split-button', SplitButtonController);
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'split-button',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('keeps the menu hidden until the chevron opens it', () => {
    expect(controller.panelTarget.hidden).toBe(true);
    expect(controller.triggerTarget.getAttribute('aria-expanded')).toBe(
        'false',
    );
    controller.triggerTarget.click();
    expect(controller.panelTarget.hidden).toBe(false);
    expect(controller.triggerTarget.getAttribute('aria-expanded')).toBe('true');
    controller.triggerTarget.click();
    expect(controller.panelTarget.hidden).toBe(true);
});

it('closes on Escape, returns focus and leaves a surrounding drawer open', () => {
    controller.open();
    document.getElementById('first').focus();
    const outerKeydown = vi.fn();
    document.addEventListener('keydown', outerKeydown);
    const event = press(document.getElementById('first'), 'Escape');
    document.removeEventListener('keydown', outerKeydown);
    expect(controller.panelTarget.hidden).toBe(true);
    expect(document.activeElement).toBe(controller.triggerTarget);
    expect(event.defaultPrevented).toBe(true);
    expect(outerKeydown).not.toHaveBeenCalled();
});

it('ignores Escape while the menu is closed', () => {
    const event = press(controller.triggerTarget, 'Escape');
    expect(event.defaultPrevented).toBe(false);
});

it('closes on a click outside and leaves focus where the click put it', () => {
    controller.open();
    const outside = document.getElementById('outside');
    outside.focus();
    outside.click();
    expect(controller.panelTarget.hidden).toBe(true);
    expect(document.activeElement).toBe(outside);
});

it('does not close on a click inside the split button', () => {
    controller.open();
    document.getElementById('main').click();
    expect(controller.panelTarget.hidden).toBe(false);
});

it('moves focus with the arrow keys and skips a disabled row', () => {
    controller.open();
    const first = document.getElementById('first');
    const last = document.getElementById('last');
    press(controller.panelTarget, 'ArrowDown');
    expect(document.activeElement).toBe(first);
    press(first, 'ArrowDown');
    expect(document.activeElement).toBe(last);
    press(last, 'ArrowDown');
    expect(document.activeElement).toBe(first);
    press(first, 'ArrowUp');
    expect(document.activeElement).toBe(last);
});

it('opens from the chevron with an arrow key and focuses a row', () => {
    controller.triggerTarget.focus();
    press(controller.triggerTarget, 'ArrowDown');
    expect(controller.panelTarget.hidden).toBe(false);
    expect(document.activeElement).toBe(document.getElementById('first'));
});

it('closes before Turbo caches the page', () => {
    controller.open();
    document.dispatchEvent(new Event('turbo:before-cache'));
    expect(controller.panelTarget.hidden).toBe(true);
});
