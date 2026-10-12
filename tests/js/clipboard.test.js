/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ClipboardController from '../../assets/controllers/clipboard_controller.js';

let application;

async function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('clipboard', ClipboardController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

async function click(button) {
    button.click();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
    vi.stubGlobal('navigator', {
        clipboard: { writeText: vi.fn(() => Promise.resolve()) },
    });
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('swaps the label of a text button and restores it', async () => {
    await mount(`<span data-controller="clipboard" data-clipboard-text-value="https://x/a" data-clipboard-copied-label-value="Copied!">
        <button data-clipboard-target="button" data-action="click->clipboard#copy">Copy link</button>
    </span>`);
    const button = document.querySelector('button');
    await click(button);
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://x/a');
    expect(button.textContent).toBe('Copied!');
    expect(button.hasAttribute('data-copied')).toBe(true);
});

it('keeps the icon of an icon button and puts the copied label in its label target', async () => {
    await mount(`<span data-controller="clipboard" data-clipboard-text-value="https://x/b" data-clipboard-copied-label-value="Copied!">
        <button data-clipboard-target="button" data-action="click->clipboard#copy"><svg class="icon"></svg><span data-clipboard-target="label">Copy link</span></button>
    </span>`);
    const button = document.querySelector('button');
    const label = document.querySelector('[data-clipboard-target="label"]');
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    button.click();
    await Promise.resolve();
    await Promise.resolve();
    expect(button.querySelector('svg.icon')).not.toBeNull();
    expect(label.textContent).toBe('Copied!');
    expect(button.hasAttribute('data-copied')).toBe(true);
    vi.advanceTimersByTime(2000);
    expect(label.textContent).toBe('Copy link');
    expect(button.hasAttribute('data-copied')).toBe(false);
});
