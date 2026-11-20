/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import FlashController from '../../assets/controllers/flash_controller.js';

let application;

async function mount(attributes) {
    document.body.innerHTML = `<div id="flash" data-controller="flash" ${attributes}></div>`;
    const element = document.getElementById('flash');
    element.animate = vi.fn(() => ({ finished: Promise.resolve() }));
    application = Application.start();
    application.register('flash', FlashController);
    await vi.advanceTimersByTimeAsync(0);

    return element;
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('fades out after its auto-dismiss delay', async () => {
    const element = await mount('data-flash-auto-dismiss-value="5000"');
    await vi.advanceTimersByTimeAsync(4999);
    expect(element.animate).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(element.animate).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(0);
    expect(element.isConnected).toBe(false);
});

it('stays until dismissed when it has no auto-dismiss delay', async () => {
    const element = await mount('');
    await vi.advanceTimersByTimeAsync(60000);
    expect(element.animate).not.toHaveBeenCalled();
    expect(element.isConnected).toBe(true);
});

it('restarts the delay when a morph reuses the element', async () => {
    const element = await mount('data-flash-auto-dismiss-value="5000"');
    await vi.advanceTimersByTimeAsync(4000);
    element.dispatchEvent(new CustomEvent('turbo:morph-element'));
    await vi.advanceTimersByTimeAsync(4000);
    expect(element.animate).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1000);
    expect(element.animate).toHaveBeenCalledOnce();
});
