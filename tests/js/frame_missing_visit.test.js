/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import FrameMissingVisitController from '../../assets/controllers/frame_missing_visit_controller.js';

let application;

beforeEach(async () => {
    application = Application.start();
    application.register('frame-missing-visit', FrameMissingVisitController);
    document.body.innerHTML = `<div id="frame" data-controller="frame-missing-visit"
        data-action="turbo:frame-missing->frame-missing-visit#visit"></div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('visits the response as a full page when the frame is missing', () => {
    const response = { url: 'https://example.test/register' };
    const visit = vi.fn();
    const event = new CustomEvent('turbo:frame-missing', {
        cancelable: true,
        detail: { response, visit },
    });

    document.getElementById('frame').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    expect(visit).toHaveBeenCalledWith(response);
});
