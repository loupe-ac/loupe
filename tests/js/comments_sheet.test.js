/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import CommentsSheetController from '../../assets/controllers/comments_sheet_controller.js';

let application;

beforeEach(async () => {
    document.body.innerHTML = `<div data-controller="comments-sheet">
        <div data-comments-sheet-target="view" data-status="open">
            <button id="open" data-comments-sheet-target="tab" aria-selected="true"
                data-comments-sheet-status-param="open" data-action="click->comments-sheet#choose">Open</button>
            <button id="resolved" data-comments-sheet-target="tab" aria-selected="false"
                data-comments-sheet-status-param="resolved" data-action="click->comments-sheet#choose">Resolved</button>
        </div>
    </div>`;
    application = Application.start();
    application.register('comments-sheet', CommentsSheetController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('sets the status on the box and marks the chosen tab', () => {
    document.getElementById('resolved').click();

    expect(
        document.querySelector('[data-comments-sheet-target="view"]').dataset
            .status,
    ).toBe('resolved');
    expect(document.getElementById('resolved').ariaSelected).toBe('true');
    expect(document.getElementById('open').getAttribute('aria-selected')).toBe(
        'false',
    );
});
