/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DashboardAgeController from '../../assets/controllers/dashboard_age_controller.js';

const NOW = 1_800_000_000;
let application;

async function mount(tiles) {
    document.body.innerHTML = `<div data-controller="dashboard-age" data-dashboard-age-now-value="${NOW}" data-dashboard-age-text-value="Updated %minutes% min ago">
        <p data-dashboard-age-target="line" hidden></p>
        <div id="grid">${tiles}</div>
    </div>`;
    await vi.advanceTimersByTimeAsync(0);
}

const line = () => document.querySelector('[data-dashboard-age-target]');
const tile = (secondsAgo) =>
    `<div data-computed-at="${NOW - secondsAgo}"></div>`;

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW * 1000);
    application = Application.start();
    application.register('dashboard-age', DashboardAgeController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
});

it('counts from the oldest tile', async () => {
    await mount(tile(60) + tile(250) + tile(10));

    expect(line().hidden).toBe(false);
    expect(line().textContent).toBe('Updated 4 min ago');
});

it('hides the line while no tile shows a result', async () => {
    await mount('');

    expect(line().hidden).toBe(true);
});

it('shows the line when a lazy tile arrives', async () => {
    await mount('');

    document.getElementById('grid').insertAdjacentHTML('beforeend', tile(120));
    await vi.advanceTimersByTimeAsync(0);

    expect(line().hidden).toBe(false);
    expect(line().textContent).toBe('Updated 2 min ago');
});

it('ages the line as the page stays open', async () => {
    await mount(tile(0));
    expect(line().textContent).toBe('Updated 0 min ago');

    await vi.advanceTimersByTimeAsync(5 * 60 * 1000);

    expect(line().textContent).toBe('Updated 5 min ago');
});
