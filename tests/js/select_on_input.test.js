/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import SelectOnInputController from '../../assets/controllers/select_on_input_controller.js';

let application;

beforeEach(async () => {
    document.body.innerHTML = `
        <form>
            <input type="radio" name="project" value="p1" id="existing" checked>
            <div data-controller="select-on-input">
                <input type="radio" name="project" value="" id="new" data-select-on-input-target="radio">
                <input type="text" id="name" data-action="focus->select-on-input#select input->select-on-input#select">
            </div>
        </form>`;
    application = Application.start();
    application.register('select-on-input', SelectOnInputController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.innerHTML = '';
});

it('checks the new project radio when its field gets focus', () => {
    document.getElementById('name').dispatchEvent(new Event('focus'));

    expect(document.getElementById('new').checked).toBe(true);
    expect(document.getElementById('existing').checked).toBe(false);
});

it('checks the new project radio again when the person types after picking a project', () => {
    const name = document.getElementById('name');
    name.dispatchEvent(new Event('focus'));
    document.getElementById('existing').checked = true;
    name.dispatchEvent(new Event('input'));

    expect(document.getElementById('new').checked).toBe(true);
});
