/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReviewJumpController from '../../assets/controllers/review_jump_controller.js';

let application;
let heading;

beforeEach(async () => {
    document.body.innerHTML = `
        <div data-controller="review-jump">
            <a href="#risks" data-action="click->review-jump#go">Risks</a>
            <a href="#missing" data-action="click->review-jump#go">Missing</a>
        </div>
        <h2 id="risks">Risks</h2>`;
    heading = document.getElementById('risks');
    heading.scrollIntoView = vi.fn();
    application = Application.start();
    application.register('review-jump', ReviewJumpController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('scrolls the linked heading to the top and cancels the link', () => {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });

    document.querySelector('a[href="#risks"]').dispatchEvent(event);

    expect(heading.scrollIntoView).toHaveBeenCalledWith({ block: 'start' });
    expect(event.defaultPrevented).toBe(true);
});

it('does nothing when the heading is gone', () => {
    document.querySelector('a[href="#missing"]').click();

    expect(heading.scrollIntoView).not.toHaveBeenCalled();
});
