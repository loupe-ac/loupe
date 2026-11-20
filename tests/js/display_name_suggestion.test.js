/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import DisplayNameSuggestionController from '../../assets/controllers/display_name_suggestion_controller.js';

let application;
let form;
let email;
let displayName;

beforeEach(async () => {
    document.body.innerHTML = `<form data-controller="display-name-suggestion">
        <input data-display-name-suggestion-target="email" data-action="input->display-name-suggestion#suggest">
        <input data-display-name-suggestion-target="displayName" data-action="input->display-name-suggestion#recordEdit">
    </form>`;
    form = document.querySelector('form');
    [email, displayName] = form.querySelectorAll('input');
    application = Application.start();
    application.register(
        'display-name-suggestion',
        DisplayNameSuggestionController,
    );
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

function typeEmail(value) {
    email.value = value;
    email.dispatchEvent(new Event('input', { bubbles: true }));
}

it('fills the name from the email until the person types a name', () => {
    typeEmail('ada.lovelace@example.com');
    expect(displayName.value).toBe('Ada Lovelace');
});

it('keeps a name that a refused submission brought back after a morph', () => {
    displayName.value = 'Ada';
    form.dispatchEvent(new CustomEvent('turbo:morph-element'));
    typeEmail('someone.else@example.com');
    expect(displayName.value).toBe('Ada');
});

it('suggests again when a morph brings back an empty name', () => {
    displayName.value = 'Ada';
    form.dispatchEvent(new CustomEvent('turbo:morph-element'));
    displayName.value = '';
    form.dispatchEvent(new CustomEvent('turbo:morph-element'));
    typeEmail('grace.hopper@example.com');
    expect(displayName.value).toBe('Grace Hopper');
});
