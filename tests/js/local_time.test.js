/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import LocalTimeController, {
    localParts,
} from '../../assets/controllers/local_time_controller.js';

let application;

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
});

const reset = new Date('2026-11-01T17:00:00Z');

it('reads a reset in the zone of the reader, which can differ from the zone of the server', () => {
    expect(localParts(reset, 'UTC')).toEqual({
        date: 'Sun 1 Nov',
        time: '17:00',
    });
    expect(localParts(reset, 'Europe/Paris')).toEqual({
        date: 'Sun 1 Nov',
        time: '18:00',
    });
    expect(localParts(reset, 'Pacific/Auckland')).toEqual({
        date: 'Mon 2 Nov',
        time: '06:00',
    });
});

it('rewrites the date and the time elements in the zone of the browser', async () => {
    document.body.innerHTML = `
        <time id="date" datetime="2026-11-01T17:00:00+00:00" data-controller="local-time" data-local-time-part-value="date">Sun 1 Nov</time>
        <time id="time" datetime="2026-11-01T17:00:00+00:00" data-controller="local-time" data-local-time-part-value="time">17:00 UTC</time>`;
    application = Application.start();
    application.register('local-time', LocalTimeController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    const expected = localParts(reset);
    expect(document.getElementById('date').textContent).toBe(expected.date);
    expect(document.getElementById('time').textContent).toBe(expected.time);
});

it('rewrites the time again after a morph puts the server text back', async () => {
    document.body.innerHTML = `<time id="time" datetime="2026-11-01T17:00:00+00:00" data-controller="local-time" data-local-time-part-value="time">17:00 UTC</time>`;
    application = Application.start();
    application.register('local-time', LocalTimeController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    const element = document.getElementById('time');
    element.textContent = '17:00 UTC';
    element.dispatchEvent(new CustomEvent('turbo:morph-element'));

    expect(element.textContent).toBe(localParts(reset).time);
});

it('keeps the server text when the datetime is not a date', async () => {
    document.body.innerHTML = `<time id="time" datetime="soon" data-controller="local-time" data-local-time-part-value="time">17:00 UTC</time>`;
    application = Application.start();
    application.register('local-time', LocalTimeController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(document.getElementById('time').textContent).toBe('17:00 UTC');
});
