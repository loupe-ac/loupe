/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DashboardAgeController from '../../assets/controllers/dashboard_age_controller.js';

const NOW = 1_800_000_000;
const TEXTS = {
    minutes: 'Updated %count% min ago',
    hour: 'Updated 1 hour ago',
    hours: 'Updated %count% hours ago',
    day: 'Updated 1 day ago',
    days: 'Updated %count% days ago',
};
let application;

async function mount(tiles) {
    document.body.innerHTML = `<div data-controller="dashboard-age" data-dashboard-age-now-value="${NOW}" data-dashboard-age-texts-value='${JSON.stringify(TEXTS)}'>
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

it('skips a tile that holds no result', async () => {
    await mount(tile(120) + '<div data-computed-at=""></div>');

    expect(line().textContent).toBe('Updated 2 min ago');
});

it('counts in hours under a day and in days after', async () => {
    await mount(tile(3600) + tile(60));
    expect(line().textContent).toBe('Updated 1 hour ago');

    document.body.replaceChildren();
    await mount(tile(5 * 3600 + 120));
    expect(line().textContent).toBe('Updated 5 hours ago');

    document.body.replaceChildren();
    await mount(tile(86400));
    expect(line().textContent).toBe('Updated 1 day ago');

    document.body.replaceChildren();
    await mount(tile(9 * 86400 + 5000));
    expect(line().textContent).toBe('Updated 9 days ago');
});

it('rolls the line over from minutes to hours as the page stays open', async () => {
    await mount(tile(59 * 60));
    expect(line().textContent).toBe('Updated 59 min ago');

    await vi.advanceTimersByTimeAsync(60 * 1000);

    expect(line().textContent).toBe('Updated 1 hour ago');
});
