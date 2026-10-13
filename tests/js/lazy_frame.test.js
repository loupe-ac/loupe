/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import LazyFrameController from '../../assets/controllers/lazy_frame_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    application = Application.start();
    application.register('lazy-frame', LazyFrameController);
    document.body.innerHTML = `<div data-controller="lazy-frame" data-lazy-frame-url-value="/recent">
        <p data-lazy-frame-target="loading">Loading</p>
        <p data-lazy-frame-target="error" hidden>Failed</p>
        <div data-lazy-frame-target="frame" hidden></div>
    </div>`;
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'lazy-frame',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

const response = (succeeded, isHTML) => ({
    detail: { fetchResponse: { succeeded, isHTML } },
    preventDefault() {
        this.prevented = true;
    },
});

it('sets the frame source on the first load and shows the loading state', () => {
    controller.load();
    expect(controller.frameTarget.src).toContain('/recent');
    expect(controller.loadingTarget.hidden).toBe(false);
    expect(controller.frameTarget.hidden).toBe(true);
});

it('shows the frame once it has loaded', () => {
    controller.loaded();
    expect(controller.frameTarget.hidden).toBe(false);
    expect(controller.loadingTarget.hidden).toBe(true);
    expect(controller.errorTarget.hidden).toBe(true);
});

it('shows the error for a response that is not an HTML success', () => {
    const event = response(true, false);
    controller.received(event);
    expect(event.prevented).toBe(true);
    expect(controller.errorTarget.hidden).toBe(false);
    expect(controller.frameTarget.hidden).toBe(true);
});

it('keeps the loading state for an HTML success', () => {
    const event = response(true, true);
    controller.load();
    controller.received(event);
    expect(event.prevented).toBeUndefined();
    expect(controller.errorTarget.hidden).toBe(true);
});
