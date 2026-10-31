/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import LeaveGuardController from '../../assets/controllers/leave_guard_controller.js';
import ModalController from '../../assets/controllers/modal_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(dirty) {
    document.body.innerHTML = `
        <a id="outside" href="/projects/1/analytics/reports">Reports</a>
        <a id="anchor" href="${window.location.pathname}#top">Top</a>
        <div data-controller="modal leave-guard" data-leave-guard-dirty-value="${dirty}">
            <a id="back" href="/projects/1/analytics/metrics">Back</a>
            <dialog data-modal-target="dialog">
                <button id="keep" type="button" data-action="click->modal#close">Keep editing</button>
                <button id="discard" type="button" data-action="click->leave-guard#discard">Discard changes</button>
            </dialog>
        </div>`;
    const dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
    });
    dialog.getAnimations = () => [];
    application = Application.start();
    application.register('modal', ModalController);
    application.register('leave-guard', LeaveGuardController);
    await settle();

    return dialog;
}

function click(selector) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector(selector).dispatchEvent(event);

    return event;
}

beforeEach(() => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    window.Turbo = { visit: vi.fn() };
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    delete window.Turbo;
    vi.unstubAllGlobals();
});

it('lets a link through when nothing changed', async () => {
    const dialog = await mount(false);
    const event = click('#outside');
    expect(event.defaultPrevented).toBe(false);
    expect(dialog.showModal).not.toHaveBeenCalled();
});

it('opens the dialog on a link click when the page changed', async () => {
    const dialog = await mount(true);
    const event = click('#outside');
    expect(event.defaultPrevented).toBe(true);
    expect(dialog.showModal).toHaveBeenCalledTimes(1);
    expect(window.Turbo.visit).not.toHaveBeenCalled();
});

it('guards the back link as well', async () => {
    const dialog = await mount(true);
    click('#back');
    expect(dialog.showModal).toHaveBeenCalledTimes(1);
});

it('leaves a link to the same page alone', async () => {
    const dialog = await mount(true);
    const event = click('#anchor');
    expect(event.defaultPrevented).toBe(false);
    expect(dialog.showModal).not.toHaveBeenCalled();
});

it('stays on the page when the reader keeps editing', async () => {
    const dialog = await mount(true);
    click('#outside');
    click('#keep');
    await settle();
    expect(dialog.open).toBe(false);
    expect(window.Turbo.visit).not.toHaveBeenCalled();
});

it('goes to the link when the reader discards the changes', async () => {
    const dialog = await mount(true);
    click('#outside');
    click('#discard');
    await settle();
    expect(dialog.open).toBe(false);
    expect(window.Turbo.visit).toHaveBeenCalledWith(
        `${window.location.origin}/projects/1/analytics/reports`,
    );
});

it('asks the browser to confirm a tab close only when the page changed', async () => {
    await mount(true);
    const dirtyEvent = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(dirtyEvent);
    expect(dirtyEvent.defaultPrevented).toBe(true);

    document.body.querySelector(
        '[data-controller]',
    ).dataset.leaveGuardDirtyValue = 'false';
    await settle();
    const cleanEvent = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(cleanEvent);
    expect(cleanEvent.defaultPrevented).toBe(false);
});

it('does not ask when a form is submitting', async () => {
    await mount(true);
    document.dispatchEvent(new Event('submit', { bubbles: true }));
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
});
