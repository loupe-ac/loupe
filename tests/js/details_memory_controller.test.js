/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import DetailsMemoryController, {
    choices,
} from '../../assets/controllers/details_memory_controller.js';

let application;

const SECTION = (open) =>
    `<details data-controller="details-memory" data-details-memory-key="accounts-1"${open ? ' open' : ''}><summary>Accounts</summary></details>`;

async function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('details-memory', DetailsMemoryController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    choices.clear();
});

it('leaves the server state alone until the reader toggles the section', async () => {
    await mount(SECTION(true));

    expect(document.querySelector('details').open).toBe(true);
});

it('keeps a section the reader closed closed after the frame reloads', async () => {
    await mount(SECTION(true));
    const first = document.querySelector('details');
    first.open = false;
    first.dispatchEvent(new Event('toggle'));

    document.body.innerHTML = SECTION(true);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(document.querySelector('details').open).toBe(false);
});

it('keeps a section the reader opened open after the frame reloads', async () => {
    await mount(SECTION(false));
    const first = document.querySelector('details');
    first.open = true;
    first.dispatchEvent(new Event('toggle'));

    document.body.innerHTML = SECTION(false);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(document.querySelector('details').open).toBe(true);
});
