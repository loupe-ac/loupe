/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import DetailsMemoryController, {
    choices,
} from '../../assets/controllers/details_memory_controller.js';

let application;

const SECTION = (open, key = 'accounts-1') =>
    `<details data-controller="details-memory" data-details-memory-key-value="${key}"${open ? ' open' : ''}><summary>${key}</summary></details>`;

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(html) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('details-memory', DetailsMemoryController);
    await tick();
}

async function reload(html) {
    document.body.innerHTML = html;
    await tick();
}

function readerSets(details, open) {
    details
        .querySelector('summary')
        .dispatchEvent(new MouseEvent('click', { bubbles: true }));
    details.open = open;
    details.dispatchEvent(new Event('toggle'));
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
    readerSets(document.querySelector('details'), false);

    await reload(SECTION(true));

    expect(document.querySelector('details').open).toBe(false);
});

it('keeps a section the reader opened open after the frame reloads', async () => {
    await mount(SECTION(false));
    readerSets(document.querySelector('details'), true);

    await reload(SECTION(false));

    expect(document.querySelector('details').open).toBe(true);
});

it('reads the key from the Stimulus value, so each section keeps its own choice', async () => {
    await mount(SECTION(true, 'pools-1') + SECTION(true, 'accounts-1'));
    readerSets(
        document.querySelector('[data-details-memory-key-value="pools-1"]'),
        false,
    );

    await reload(SECTION(true, 'pools-1') + SECTION(true, 'accounts-1'));

    expect([...choices.keys()]).toEqual(['pools-1']);
    expect(
        document.querySelector('[data-details-memory-key-value="pools-1"]')
            .open,
    ).toBe(false);
    expect(
        document.querySelector('[data-details-memory-key-value="accounts-1"]')
            .open,
    ).toBe(true);
});

it('ignores the toggle that a rendered open attribute fires', async () => {
    await mount(SECTION(true));
    document.querySelector('details').dispatchEvent(new Event('toggle'));

    await reload(SECTION(false));

    expect(choices.size).toBe(0);
    expect(document.querySelector('details').open).toBe(false);
});

it('starts the next visit from the server state', async () => {
    await mount(SECTION(true));
    readerSets(document.querySelector('details'), false);

    document.dispatchEvent(new Event('turbo:visit'));
    await reload(SECTION(true));

    expect(document.querySelector('details').open).toBe(true);
});
