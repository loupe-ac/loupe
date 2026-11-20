/**
 * Browser coverage for the live Backlog page: a card that another browser adds
 * shows in an open page without a reload, a row that the reader ticked stays
 * ticked through the update, and a card that moves away leaves the list. The
 * hub delivers the signal, so the run needs a hub.
 */

import { test, expect } from '@playwright/test';
import { expectLiveRegionConnected, mcpCards, signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBacklogLive1!';

test.setTimeout(180_000);

test('a card added elsewhere appears, a ticked row stays ticked, and a moved card leaves', async ({
    browser,
    request,
}) => {
    const email = `e2e+backlog+live+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Backlog Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Backlog Live Project', markdown: '# Backlog' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = (await seeded.json()).projectId as string;

    const actor = await signedInPage(browser, email, PASSWORD);
    const create = await mcpCards(actor, projectId);
    const first = await create('First waiting');
    const second = await create('Second waiting');

    await watcher.goto(`/projects/${projectId}/board/backlog`);
    await expect(watcher.locator('[data-backlog-card-id]')).toHaveCount(2);
    await expect(
        watcher.locator('turbo-frame#backlog-list #backlog-live'),
    ).toBeVisible();
    // The hub keeps no history, so a change made before the page connects is lost.
    await expectLiveRegionConnected(watcher);
    await watcher
        .getByRole('checkbox', { name: `Select card #${first.number}` })
        .check();
    await watcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });
    // A row update keeps the element that is on screen, and a list swap replaces it.
    await watcher
        .locator(`[data-backlog-card-id="${first.id}"]`)
        .evaluate((row) => {
            (row as unknown as { kept: boolean }).kept = true;
        });

    const third = await create('Third waiting');
    await expect(
        watcher.locator(`[data-backlog-card-id="${third.id}"]`),
    ).toBeVisible({ timeout: 15_000 });
    await expect(
        watcher.getByRole('checkbox', { name: `Select card #${first.number}` }),
    ).toBeChecked();
    expect(
        await watcher
            .locator(`[data-backlog-card-id="${first.id}"]`)
            .evaluate(
                (row) => (row as unknown as { kept?: boolean }).kept === true,
            ),
    ).toBe(true);
    await expect(
        watcher.locator('[data-backlog-card-id]').first(),
    ).toHaveAttribute('data-backlog-card-id', third.id);

    await create.move(second, 'next');

    await expect(
        watcher.locator(`[data-backlog-card-id="${second.id}"]`),
    ).toHaveCount(0, { timeout: 15_000 });
    await expect(watcher.locator('[data-backlog-card-id]')).toHaveCount(2);
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);
});

test('the first card of an empty Backlog shows the count and the filter bar', async ({
    browser,
    request,
}) => {
    const email = `e2e+backlog+first+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Backlog First', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Backlog First Project', markdown: '# Backlog' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = (await seeded.json()).projectId as string;

    await watcher.goto(`/projects/${projectId}/board/backlog`);
    await expect(watcher.locator('[data-backlog-card-id]')).toHaveCount(0);
    await expect(watcher.locator('#backlog-count')).toBeHidden();
    await expect(watcher.locator('#backlog-filter')).toHaveCount(0);
    await expectLiveRegionConnected(watcher);

    const actor = await signedInPage(browser, email, PASSWORD);
    const create = await mcpCards(actor, projectId);
    await create('First arrival');

    await expect(watcher.locator('[data-backlog-card-id]')).toHaveCount(1, {
        timeout: 15_000,
    });
    await expect(watcher.locator('#backlog-count')).toBeVisible();
    await expect(watcher.locator('#backlog-filter')).toBeVisible();
});
