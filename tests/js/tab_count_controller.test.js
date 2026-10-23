/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';

const { default: TabCountController } =
    await import('../../assets/controllers/tab_count_controller.js');

let application;

function mount(total) {
    document.body.innerHTML = `
        <div data-controller="tab-count">
            <turbo-frame id="inbox-open-total">${
                total === null
                    ? ''
                    : `<span data-inbox-open-total>${total}</span>`
            }</turbo-frame>
        </div>`;
}

beforeEach(() => {
    document.title = 'Board — Loupe';
    application = Application.start();
    application.register('tab-count', TabCountController);
});

afterEach(() => {
    application.stop();
    document.body.innerHTML = '';
});

it('prefixes the title with the total on connect', async () => {
    mount(3);
    await Promise.resolve();

    expect(document.title).toBe('(3) Board — Loupe');
});

it('leaves the title alone at zero or with no counter', async () => {
    mount(null);
    await Promise.resolve();

    expect(document.title).toBe('Board — Loupe');
});

it('replaces a prefix it wrote earlier, and removes it at zero', async () => {
    mount(3);
    await Promise.resolve();
    const element = document.querySelector('[data-controller="tab-count"]');
    const controller = application.getControllerForElementAndIdentifier(
        element,
        'tab-count',
    );

    document.querySelector('[data-inbox-open-total]').textContent = '5';
    controller.sync();
    controller.sync();
    expect(document.title).toBe('(5) Board — Loupe');

    document.querySelector('[data-inbox-open-total]').remove();
    controller.sync();
    expect(document.title).toBe('Board — Loupe');
});

it('strips a prefix that the incoming page title already carries', async () => {
    document.title = '(9) Board — Loupe';
    mount(2);
    await Promise.resolve();

    expect(document.title).toBe('(2) Board — Loupe');
});
