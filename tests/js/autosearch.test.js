/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import AutosearchController from '../../assets/controllers/autosearch_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(checked) {
    application = Application.start();
    application.register('autosearch', AutosearchController);
    document.body.innerHTML = `<form data-controller="autosearch">
        <input type="search" name="search" value="">
        <input type="checkbox" name="archived" value="1"${checked ? ' checked' : ''}>
        <a data-autosearch-target="clearButton">Clear</a>
    </form>`;
    await settle();

    return document.querySelector('[data-autosearch-target="clearButton"]');
}

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('treats an unchecked switch as no filter', async () => {
    const clear = await mount(false);
    expect(clear.classList.contains('pointer-events-none')).toBe(true);
});

it('treats a checked switch as an active filter', async () => {
    const clear = await mount(true);
    expect(clear.classList.contains('pointer-events-none')).toBe(false);
});

it('empties the fields and submits when the clear link is clicked', async () => {
    application = Application.start();
    application.register('autosearch', AutosearchController);
    document.body.innerHTML = `<form data-controller="autosearch">
        <input type="search" name="search" value="otter">
        <select name="type"><option value=""></option><option value="bug" selected>bug</option></select>
        <input type="checkbox" name="archived" value="1" checked>
        <input type="hidden" name="sort" value="type">
        <a data-autosearch-target="clearButton" data-action="click->autosearch#clear">Clear</a>
    </form>`;
    await settle();
    const form = document.querySelector('form');
    let submitted = 0;
    form.requestSubmit = () => (submitted += 1);

    const click = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector('a').dispatchEvent(click);

    expect(click.defaultPrevented).toBe(true);
    expect(form.elements.search.value).toBe('');
    expect(form.elements.type.value).toBe('');
    expect(form.elements.archived.checked).toBe(false);
    expect(form.elements.sort.value).toBe('type');
    expect(submitted).toBe(1);
});

it('clears the form that an outside button names', async () => {
    application = Application.start();
    application.register('autosearch', AutosearchController);
    document.body.innerHTML = `<form id="list-filter" data-controller="autosearch">
        <input type="search" name="search" value="otter">
        <a data-autosearch-target="clearButton">Clear</a>
    </form>
    <a id="empty-clear" href="/list" data-clear-filter-form="list-filter">Clear filters</a>
    <a id="other" href="/x" data-clear-filter-form="other-form">Other</a>`;
    await settle();
    const form = document.querySelector('form');
    let submitted = 0;
    form.requestSubmit = () => (submitted += 1);

    const other = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector('#other').dispatchEvent(other);
    expect(other.defaultPrevented).toBe(false);
    expect(submitted).toBe(0);

    const click = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector('#empty-clear').dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(form.elements.search.value).toBe('');
    expect(submitted).toBe(1);
});

it('copies a switch from the loaded address when its frame renders', async () => {
    application = Application.start();
    application.register('autosearch', AutosearchController);
    document.body.innerHTML = `<form data-controller="autosearch" data-autosearch-sync-value="true"
            data-turbo-frame="list" action="http://localhost/experiments">
        <input type="checkbox" name="archived" value="1">
    </form><turbo-frame id="list"></turbo-frame>`;
    await settle();
    const form = document.querySelector('form');
    const render = (address) =>
        document.querySelector('turbo-frame').dispatchEvent(
            new CustomEvent('turbo:frame-render', {
                bubbles: true,
                detail: { fetchResponse: { response: { url: address } } },
            }),
        );

    render('http://localhost/experiments?archived=1');
    expect(form.elements.archived.checked).toBe(true);

    render('http://localhost/experiments/other/archive');
    expect(form.elements.archived.checked).toBe(true);

    render('http://localhost/experiments');
    expect(form.elements.archived.checked).toBe(false);
});
