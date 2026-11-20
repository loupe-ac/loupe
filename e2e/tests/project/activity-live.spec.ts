/**
 * Browser coverage for the live Events pages: page one follows a new event in
 * place, a later page shows a band for events newer than its rows and keeps its
 * rows where they are, and the project outbox list picks up a new unsent row.
 * The hub delivers the signal, so the run needs a hub.
 */

import { test, expect, type Page } from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eActivityLive1!';

test.setTimeout(150_000);

async function seedEvents(
    page: Page,
    name: string,
    published: number,
    unsent: number,
): Promise<string> {
    const response = await page.request.post('/dev/seed/activity-events', {
        form: { name, published, unsent },
    });
    expect(response.status()).toBe(201);

    return (await response.json()).projectId;
}

/** One rename records one project.renamed event, and tells the open pages. */
async function rename(page: Page, projectId: string, name: string) {
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByLabel('Project name', { exact: true }).fill(name);
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('link', { name: `Edit ${name}`, exact: true }),
    ).toBeVisible({ timeout: 30_000 });
}

/** The hub keeps no history, so a change made before the page connects is lost. */
async function connected(page: Page): Promise<void> {
    await page.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });
    await expect(
        page.locator('[data-controller~="live-region"]').first(),
    ).toBeAttached();
    await page.waitForTimeout(1_500);
}

async function stayed(page: Page): Promise<boolean> {
    return page.evaluate(
        () => (window as unknown as { stayed?: boolean }).stayed === true,
    );
}

test('page one follows a new event, and page two shows a band and keeps its rows', async ({
    browser,
    request,
}) => {
    const email = `e2e+activity+live+${RUN}+r${test.info().repeatEachIndex}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Activity Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const actor = await signedInPage(browser, email, PASSWORD);
    const projectId = await seedEvents(actor, `Live ${RUN}`, 25, 0);

    const first = await signedInPage(browser, email, PASSWORD);
    const second = await first.context().newPage();
    await first.goto(`/projects/${projectId}/activity`);
    await second.goto(`/projects/${projectId}/activity?page=2`);
    await expect(first.locator('[data-activity-event-id]')).toHaveCount(20);
    await expect(first.locator('.lp-list-count')).toHaveText('25 events');
    const laterRows = second.locator('[data-activity-event-id]');
    await expect(laterRows).toHaveCount(5);
    await expect(second.locator('[data-activity-live-newer]')).toHaveCount(0);
    const before = await laterRows.evaluateAll((rows) =>
        rows.map((row) => row.getAttribute('data-activity-event-id')),
    );
    await connected(first);
    await connected(second);

    await rename(actor, projectId, `Live ${RUN} renamed`);

    await expect(first.locator('.lp-list-count')).toHaveText('26 events', {
        timeout: 15_000,
    });
    await expect(second.locator('[data-activity-live-newer]')).toContainText(
        'One newer event was recorded.',
        { timeout: 15_000 },
    );
    await expect(second.locator('.lp-list-count')).toHaveText('26 events', {
        timeout: 15_000,
    });
    await expect(
        second.locator('[data-activity-live-first-page]'),
    ).toBeVisible();
    // The new event never moves the rows under the reader.
    expect(
        await laterRows.evaluateAll((rows) =>
            rows.map((row) => row.getAttribute('data-activity-event-id')),
        ),
    ).toEqual(before);
    expect(await stayed(first)).toBe(true);
    expect(await stayed(second)).toBe(true);

    await second.locator('[data-activity-live-first-page]').click();
    await expect(second).not.toHaveURL(/page=2/, { timeout: 30_000 });

    await actor.context().close();
    await first.context().close();
});

test('the project outbox list gains an unsent row without a reload', async ({
    browser,
    request,
}) => {
    const email = `e2e+outbox+live+${RUN}+r${test.info().repeatEachIndex}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Outbox Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const actor = await signedInPage(browser, email, PASSWORD);
    const name = `Outbox ${RUN}`;
    const projectId = await seedEvents(actor, name, 0, 1);

    const watcher = await signedInPage(browser, email, PASSWORD);
    await watcher.goto(`/projects/${projectId}/outbox`);
    const rows = watcher.locator('[data-outbox-event-id]');
    await expect(rows).toHaveCount(1);
    await connected(watcher);

    // The seed writes no signal, so the rename tells the page to read again.
    await seedEvents(actor, name, 0, 2);
    await rename(actor, projectId, `${name} renamed`);

    await expect(rows).not.toHaveCount(1, { timeout: 15_000 });
    expect(await rows.count()).toBeGreaterThanOrEqual(3);
    expect(await stayed(watcher)).toBe(true);

    await actor.context().close();
    await watcher.context().close();
});
