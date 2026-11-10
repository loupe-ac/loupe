/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import RepositoryPickerController from '../../assets/controllers/repository_picker_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount() {
    application = Application.start();
    application.register('repository-picker', RepositoryPickerController);
    document.body.innerHTML = `<div data-controller="repository-picker"
            data-repository-picker-none-value="Add repositories"
            data-repository-picker-one-value="Add 1 repository"
            data-repository-picker-many-value="Add %count% repositories">
        <input type="search" data-repository-picker-target="search" data-action="input->repository-picker#filter">
        <section data-repository-picker-target="account" id="acme">
            <label data-repository-picker-target="repository" data-name="acme/api"><input type="checkbox" id="api" data-action="change->repository-picker#update"></label>
            <label data-repository-picker-target="repository" data-name="acme/web"><input type="checkbox" id="web" data-action="change->repository-picker#update"></label>
            <label data-repository-picker-target="repository" data-name="acme/held"><input type="checkbox" id="held" checked disabled></label>
        </section>
        <section data-repository-picker-target="account" id="zed">
            <label data-repository-picker-target="repository" data-name="zed/tool"><input type="checkbox" id="tool" data-action="change->repository-picker#update"></label>
        </section>
        <p data-repository-picker-target="empty" hidden>Nothing</p>
        <button type="submit" data-repository-picker-target="submit">x</button>
    </div>`;
    await settle();
}

const tick = (id) => {
    const box = document.getElementById(id);
    box.checked = true;
    box.dispatchEvent(new Event('change', { bubbles: true }));
};

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('disables the button until a repository is ticked, and ignores a greyed one', async () => {
    await mount();
    const button = document.querySelector('button');
    expect(button.disabled).toBe(true);
    expect(button.textContent).toBe('Add repositories');
});

it('counts the ticked repositories in the label', async () => {
    await mount();
    const button = document.querySelector('button');
    tick('api');
    expect(button.textContent).toBe('Add 1 repository');
    tick('web');
    expect(button.textContent).toBe('Add 2 repositories');
    expect(button.disabled).toBe(false);
});

it('hides what does not match the search, and an account with no match', async () => {
    await mount();
    const search = document.querySelector('input[type="search"]');
    search.value = 'TOOL';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    expect(document.getElementById('acme').hidden).toBe(true);
    expect(document.getElementById('zed').hidden).toBe(false);

    search.value = 'nothing-like-this';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    expect(
        document.querySelector('[data-repository-picker-target="empty"]')
            .hidden,
    ).toBe(false);
});
