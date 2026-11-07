/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import SlowNoticeController from '../../assets/controllers/slow_notice_controller.js';

let application;

async function mount() {
    document.body.innerHTML = `<div id="frame" data-controller="slow-notice" data-slow-notice-delay-value="30">
        <p id="spinner">Loading</p>
        <form hidden data-graph-drag-form id="drag"></form>
        <template data-slow-notice-target="slow"><p id="notice">Slow</p></template>
    </div>`;
    await vi.advanceTimersByTimeAsync(0);
}

beforeEach(() => {
    vi.useFakeTimers();
    application = Application.start();
    application.register('slow-notice', SlowNoticeController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
});

it('keeps the spinner until the delay passes, then shows the slow tile', async () => {
    await mount();

    await vi.advanceTimersByTimeAsync(29_999);
    expect(document.querySelector('#spinner')).not.toBeNull();
    expect(document.querySelector('#notice')).toBeNull();

    await vi.advanceTimersByTimeAsync(1);
    expect(document.querySelector('#spinner')).toBeNull();
    expect(document.querySelector('#notice')).not.toBeNull();
});

it('keeps the move form in the cell when the slow tile appears', async () => {
    await mount();

    await vi.advanceTimersByTimeAsync(30_000);
    expect(
        document.querySelector('#frame form[data-graph-drag-form]'),
    ).not.toBeNull();
});

it('shows nothing when a result replaced the frame before the delay', async () => {
    await mount();
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);

    await vi.advanceTimersByTimeAsync(60_000);
    expect(document.querySelector('#notice')).toBeNull();
});
