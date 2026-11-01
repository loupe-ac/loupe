/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import InboxReplyDraftController from '../../assets/controllers/inbox_reply_draft_controller.js';
import {
    formDraft,
    rememberFormDraft,
    useFormDraftOwner,
} from '../../assets/lib/form_drafts.js';

let application;

beforeEach(async () => {
    useFormDraftOwner('owner');
    rememberFormDraft('item-1', { identity: 'draft', text: 'x', options: [] });
    document.body.innerHTML = `<section class="lp-inbox-ask"><h2 class="lp-inbox-ask__title">Ask</h2><article data-inbox-item="1"><h3 class="lp-inbox-item__title">Item</h3><div data-controller="inbox-reply-draft" data-inbox-reply-draft-choices-value='["<b>CSV</b>"]' data-inbox-reply-draft-notes-value='["<i>Next</i>"]' data-inbox-reply-draft-key-value="item-1"><ul data-inbox-reply-draft-target="choiceList"></ul><div data-inbox-reply-draft-target="noteList"></div><button type="button" data-action="inbox-reply-draft#discard">Discard draft</button></div></article></section>`;
    application = Application.start();
    application.register('inbox-reply-draft', InboxReplyDraftController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    useFormDraftOwner('');
});

it('shows the saved choices and notes as plain text', () => {
    const element = document.querySelector('[data-controller]');
    expect(element.querySelector('li').textContent).toBe('<b>CSV</b>');
    expect(element.querySelector('li').children).toHaveLength(0);
    expect(element.querySelector('p').textContent).toBe('<i>Next</i>');
});

it('removes itself and the form memory on Discard, and moves focus to the heading', () => {
    const button = document.querySelector('button');
    button.focus();
    button.click();
    expect(document.querySelector('[data-controller]')).toBeNull();
    expect(formDraft('item-1')).toBeUndefined();
    expect(document.activeElement).toBe(
        document.querySelector('.lp-inbox-item__title'),
    );
});

it('marks each saved choice with the check icon of its template', async () => {
    document.body.innerHTML = `<div data-controller="inbox-reply-draft" data-inbox-reply-draft-choices-value='["<b>CSV</b>"]' data-inbox-reply-draft-notes-value='[]'><ul data-inbox-reply-draft-target="choiceList"></ul><template data-inbox-reply-draft-target="icon"><svg class="lp-inbox-item__choice-icon"></svg></template><div data-inbox-reply-draft-target="noteList"></div></div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
    const choice = document.querySelector('li');
    expect(
        choice.querySelector('svg.lp-inbox-item__choice-icon'),
    ).not.toBeNull();
    expect(choice.textContent).toBe('<b>CSV</b>');
});
