/**
 * The documents list, the Backlog and the activity list filter inside their frame: a search swaps
 * only the list, the address bar follows, and a live signal that another actor
 * sends keeps the filter instead of bringing the old list back. The done
 * column pages inside its frame the same way. The hub delivers the signal, so
 * the run needs a hub.
 */

import { test, expect } from '@playwright/test';
import {
    expectInPlace,
    mcpCards,
    signedInPage,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eListFrames1!';
const LIVE = { timeout: coverageScaled(20_000) };

test.setTimeout(coverageScaled(180_000));

test('a Backlog search swaps the list and a live signal keeps the filter', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+backlog+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E List Frames Project', markdown: '# Frames' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;

    const actor = await signedInPage(browser, email, PASSWORD);
    const create = await mcpCards(actor, projectId);
    await create('Otter first');
    await create('Heron first');

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/board/backlog`);
    await expect(watcher.locator('turbo-frame#backlog-list')).toBeVisible();
    await expect(watcher.locator('[data-backlog-card-id]')).toHaveCount(2);

    const search = watcher.locator('#backlog-search');
    await expectInPlace(watcher, async () => {
        await search.click();
        await watcher.keyboard.type('Otter');
        await expect(watcher).toHaveURL(/search=Otter/, LIVE);
        await expect(watcher.locator('[data-backlog-card-id]')).toHaveCount(1);
    });
    await expect(search).toHaveValue('Otter');
    await expect(search).toBeFocused();

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await create('Otter second');
    await create('Heron second');

    await expect(watcher.getByText('Otter second')).toBeVisible(LIVE);
    await expect(watcher.getByText('Heron second')).toHaveCount(0);
    await expect(watcher.getByText('Heron first')).toHaveCount(0);
    await expect(search).toHaveValue('Otter');
    await expect(watcher).toHaveURL(/search=Otter/);

    await watcher.getByRole('link', { name: 'Clear', exact: true }).click();
    await expect(search).toHaveValue('');
    await expect(watcher.getByText('Heron first')).toBeVisible(LIVE);
});

test('a documents search swaps the list and a live signal keeps the filter', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+documents+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const first = await watcher.request.post('/dev/seed/document', {
        form: { title: 'Otter notes', markdown: '# Otter notes' },
    });
    expect(first.status()).toBe(201);
    const projectId = ((await first.json()) as { projectId: string }).projectId;
    const second = await watcher.request.post('/dev/seed/document', {
        form: { title: 'Heron notes', markdown: '# Heron notes' },
    });
    expect(second.status()).toBe(201);

    const actor = await signedInPage(browser, email, PASSWORD);

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/documents`);
    await expect(watcher.locator('turbo-frame#documents-list')).toBeVisible();
    await expect(watcher.locator('[data-document-id]')).toHaveCount(2);

    const search = watcher.locator('#document-search');
    await expectInPlace(watcher, async () => {
        await search.click();
        await watcher.keyboard.type('Otter');
        await expect(watcher).toHaveURL(/search=Otter/, LIVE);
        await expect(watcher.locator('[data-document-id]')).toHaveCount(1);
    });
    await expect(search).toHaveValue('Otter');

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    const added = await actor.request.post('/dev/seed/document', {
        form: { title: 'Otter more', markdown: '# Otter more' },
    });
    expect(added.status()).toBe(201);
    const other = await actor.request.post('/dev/seed/document', {
        form: { title: 'Heron more', markdown: '# Heron more' },
    });
    expect(other.status()).toBe(201);

    await expect(watcher.getByText('Otter more')).toBeVisible(LIVE);
    await expect(watcher.getByText('Heron more')).toHaveCount(0);
    await expect(watcher.getByText('Heron notes')).toHaveCount(0);
    await expect(search).toHaveValue('Otter');
});

test('the done column pages inside its frame and keeps the page on a live signal', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+done+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Done Frames Project', markdown: '# Frames' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;

    const actor = await signedInPage(browser, email, PASSWORD);
    const create = await mcpCards(actor, projectId);
    // The page holds 25 rows, so 27 cards leave two on the second page.
    for (let i = 1; i <= 27; i++) {
        await create(`Finished ${i}`, { status: 'done' });
    }

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/board`);
    const href = await watcher
        .locator('[data-column-slug="done"] .lp-board__column-link')
        .getAttribute('href');
    expect(href).not.toBeNull();
    await watcher.goto(href as string);

    await expect(watcher.locator('turbo-frame#terminal-list')).toBeVisible();
    await expect(watcher.locator('.lp-done-row')).toHaveCount(25);

    await expectInPlace(watcher, async () => {
        await watcher
            .locator('turbo-frame#terminal-list')
            .getByRole('link', { name: '2', exact: true })
            .click();
        await expect(watcher).toHaveURL(/page=2/, LIVE);
        await expect(watcher.locator('.lp-done-row')).toHaveCount(2);
    });

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await create('Finished later', { status: 'done' });

    await expect(watcher.locator('#terminal-total')).toContainText('28', LIVE);
    await expect(watcher.locator('#terminal-live-band')).not.toBeEmpty(LIVE);
    await expect(watcher).toHaveURL(/page=2/);
    await expect(watcher.locator('.lp-done-row')).toHaveCount(2);
    await expect(watcher.getByText('Finished later')).toHaveCount(0);
});

test('an activity search and a page change swap the list and keep the filter', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+activity+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/activity-events', {
        form: { name: `Frames ${RUN}`, published: 25, unsent: 0 },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/activity`);
    await expect(watcher.locator('turbo-frame#activity-list')).toBeVisible();
    await expect(watcher.locator('[data-activity-event-id]')).toHaveCount(20);

    const search = watcher.locator('#activity-search');
    await expectInPlace(watcher, async () => {
        await search.click();
        await watcher.keyboard.type('renamed');
        await expect(watcher).toHaveURL(/search=renamed/, LIVE);
        await expect(watcher.locator('[data-activity-event-id]')).toHaveCount(
            20,
        );
    });
    await expect(search).toHaveValue('renamed');
    await expect(search).toBeFocused();

    await expectInPlace(watcher, async () => {
        await watcher
            .locator('turbo-frame#activity-list')
            .getByRole('link', { name: '2', exact: true })
            .click();
        await expect(watcher).toHaveURL(/page=2/, LIVE);
        await expect(watcher.locator('[data-activity-event-id]')).toHaveCount(
            5,
        );
    });
    await expect(watcher).toHaveURL(/search=renamed/);
    await expect(search).toHaveValue('renamed');
});
