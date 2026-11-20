/**
 * The documents list, the Backlog, the activity list, the runs and the bucket rules filter inside their frame: a search swaps
 * only the list, the address bar follows, and a live signal that another actor
 * sends keeps the filter instead of bringing the old list back. The done
 * column pages inside its frame the same way. The hub delivers the signal, so
 * the run needs a hub.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
} from '@playwright/test';
import {
    agentAccessToken,
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
    await gotoSearchable(watcher, `/projects/${projectId}/board/backlog`);
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
    await gotoSearchable(watcher, `/projects/${projectId}/documents`);
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

    // A row update keeps the element that is on screen, and a page swap replaces it.
    await watcher.locator('[data-document-id]').evaluate((row) => {
        (row as unknown as { kept: boolean }).kept = true;
    });

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
    expect(
        await watcher
            .locator('[data-document-id]', { hasText: 'Otter notes' })
            .evaluate(
                (row) => (row as unknown as { kept?: boolean }).kept === true,
            ),
    ).toBe(true);
    await expect(watcher.locator('[data-document-id]').first()).toContainText(
        'Otter more',
    );
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

test('the first page of the done column gains a new finish in place', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+donefirst+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Done First Project', markdown: '# Frames' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;

    const actor = await signedInPage(browser, email, PASSWORD);
    const create = await mcpCards(actor, projectId);
    await create('Finished first', { status: 'done' });

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/board`);
    const href = await watcher
        .locator('[data-column-slug="done"] .lp-board__column-link')
        .getAttribute('href');
    expect(href).not.toBeNull();
    await watcher.goto(href as string);
    await expect(watcher.locator('.lp-done-row')).toHaveCount(1);
    await watcher.locator('.lp-done-row').evaluate((row) => {
        (row as unknown as { kept: boolean }).kept = true;
    });

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await expectInPlace(watcher, async () => {
        await create('Finished second', { status: 'done' });
        await expect(watcher.locator('.lp-done-row')).toHaveCount(2, LIVE);
    });

    await expect(watcher.locator('#terminal-total')).toContainText('2', LIVE);
    await expect(watcher.locator('.lp-done-row').first()).toContainText(
        'Finished second',
    );
    expect(
        await watcher
            .locator('.lp-done-row', { hasText: 'Finished first' })
            .evaluate(
                (row) => (row as unknown as { kept?: boolean }).kept === true,
            ),
    ).toBe(true);
    await expect(watcher.locator('#terminal-live-band')).toHaveCount(0);
});

/** Opens a list page and waits until its lazy search controller is in place, so typing starts a search. */
async function gotoSearchable(page: Page, url: string): Promise<void> {
    const loaded = page.waitForResponse((response) =>
        response.url().includes('autosearch_controller'),
    );
    await page.goto(url);
    await loaded;
    await page.waitForTimeout(500);
}

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
    await gotoSearchable(watcher, `/projects/${projectId}/activity`);
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

test('an activity filter survives a live signal from another actor', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+activitylive+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/activity-events', {
        form: { name: `Signal ${RUN}`, published: 25, unsent: 0 },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;
    const actor = await signedInPage(browser, email, PASSWORD);

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await gotoSearchable(watcher, `/projects/${projectId}/activity`);
    const search = watcher.locator('#activity-search');
    await search.click();
    await watcher.keyboard.type('renamed');
    await expect(watcher).toHaveURL(/search=renamed/, LIVE);
    await expect(watcher.locator('.lp-list-count')).toHaveText('25 events');
    await watcher
        .locator('[data-activity-event-id]')
        .first()
        .evaluate((row) => {
            (row as unknown as { kept: boolean }).kept = true;
        });

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await actor.goto(`/projects/${projectId}/edit`);
    await actor
        .getByLabel('Project name', { exact: true })
        .fill(`Signal ${RUN} renamed`);
    await actor
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();

    await expect(watcher.locator('.lp-list-count')).toHaveText(
        '26 events',
        LIVE,
    );
    // Page one takes the new row in place, so the old rows keep their elements.
    await expect(watcher.locator('[data-activity-event-id]')).toHaveCount(20);
    expect(
        await watcher
            .locator('[data-activity-event-id]')
            .evaluateAll(
                (rows) =>
                    rows.filter(
                        (row) => (row as unknown as { kept?: boolean }).kept,
                    ).length,
            ),
    ).toBe(1);
    await expect(watcher).toHaveURL(/search=renamed/);
    await expect(search).toHaveValue('renamed');
});

test('a runs filter survives a live signal from another actor', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+runs+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Runs Frames', markdown: '# Runs' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;
    const actor = await signedInPage(browser, email, PASSWORD);
    const token = await agentAccessToken(actor);
    const bridgeId = crypto.randomUUID();
    const card = await (await mcpCards(actor, projectId))('Runs frame card');
    const report = async (data: Record<string, unknown>): Promise<string> => {
        const response = await actor.request.put(
            `/api/v1/bridge/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
            {
                headers: { Authorization: `Bearer ${token}` },
                data: {
                    bridgeId,
                    at: new Date().toISOString(),
                    workKind: 'implement',
                    subjectType: 'card',
                    subjectId: card.id,
                    cardNumber: card.number,
                    ...data,
                },
            },
        );
        expect(response.status()).toBe(201);

        return ((await response.json()) as { id: string }).id;
    };
    const finished = await report({
        state: 'unfinished',
        sessionId: crypto.randomUUID(),
        startedAt: '2026-09-23T10:00:00+00:00',
        endedAt: '2026-09-23T10:05:00+00:00',
        exitCode: 0,
        hasResult: true,
        resultStatus: 'unfinished',
        output: 'CI still runs',
    });
    await report({ state: 'queued' });

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/worker-runs`);
    await expect(watcher.locator('[data-worker-run-id]')).toHaveCount(2);
    await watcher.locator('#worker-run-outcome').selectOption('open');
    await expect(watcher).toHaveURL(/open/, LIVE);
    await expect(watcher.locator('[data-worker-run-id]')).toHaveCount(1);

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await report({ state: 'queued' });

    await expect(watcher.locator('[data-worker-run-id]')).toHaveCount(2, LIVE);
    await expect(
        watcher.locator(`[data-worker-run-id="${finished}"]`),
    ).toHaveCount(0);
    await expect(watcher).toHaveURL(/open/);
    await expect(watcher.locator('#worker-run-outcome')).toHaveValue('open');
});

test('a bucket rules range survives a live signal from another actor', async ({
    browser,
    request,
}) => {
    const email = `e2e+listframes+buckets+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Buckets Frames', markdown: '# Buckets' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;
    const actor = await signedInPage(browser, email, PASSWORD);
    const url = `/projects/${projectId}/analytics/time-buckets`;

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(url);
    const range = watcher.locator('#bucket-summary-range');
    // The select sits outside the swapped frame, so a swap keeps the same element.
    await range.evaluate((element) => element.setAttribute('data-kept', ''));
    await range.selectOption('seven-days');
    await expect(watcher).toHaveURL(/range=seven-days/, LIVE);
    await expect(range).toHaveValue('seven-days');
    await expect(range).toHaveAttribute('data-kept', '');

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await actor.goto(url);
    await actor.getByLabel('Pattern', { exact: true }).fill('make *');
    await actor.getByLabel('Bucket', { exact: true }).fill('build');
    await actor.getByRole('button', { name: 'Add the rule' }).click();
    await expect(actor.locator('[data-bucket-rule]')).toHaveCount(1);

    await expect(watcher.locator('[data-bucket-rule]')).toHaveCount(1, LIVE);
    await expect(watcher).toHaveURL(/range=seven-days/);
    await expect(range).toHaveValue('seven-days');
});

/** Opens a project with an actor that reports worker runs of experiments. */
async function experimentActor(
    browser: Browser,
    request: APIRequestContext,
    name: string,
) {
    const email = `e2e+listframes+${name}+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: `E2E ${name} Frames`, markdown: '# Experiments' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;
    const actor = await signedInPage(browser, email, PASSWORD);
    const token = await agentAccessToken(actor);
    const bridgeId = crypto.randomUUID();
    const newCard = await mcpCards(actor, projectId);
    const report = async (
        card: { id: string; number: number },
        experiment: string,
        variant: string,
    ): Promise<void> => {
        const response = await actor.request.put(
            `/api/v1/bridge/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
            {
                headers: { Authorization: `Bearer ${token}` },
                data: {
                    bridgeId,
                    at: new Date().toISOString(),
                    workKind: 'implement',
                    subjectType: 'card',
                    subjectId: card.id,
                    cardNumber: card.number,
                    state: 'queued',
                    experiment,
                    variant,
                },
            },
        );
        expect(response.status()).toBe(201);
    };

    return { watcher, actor, projectId, newCard, report };
}

test('an experiments archived filter survives a live signal from another actor', async ({
    browser,
    request,
}) => {
    const { watcher, actor, projectId, newCard, report } =
        await experimentActor(browser, request, 'experiments');
    const card = await newCard('Experiments frame card');
    await report(card, 'frame-first', 'control');
    await report(card, 'frame-second', 'control');
    await report(card, 'frame-third', 'control');
    const url = `/projects/${projectId}/analytics/experiments`;

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(url);
    await expect(watcher.locator('[data-experiment]')).toHaveCount(3);
    await watcher.locator('[data-experiment-row-archive]').first().click();
    await expect(watcher.locator('[data-experiment]')).toHaveCount(2, LIVE);
    // The archive signals its own change, and the live read it starts must end before the toggle.
    await watcher.waitForTimeout(2_000);
    const toggle = watcher.locator('[data-experiment-archived-toggle]');
    await expect(toggle).toBeVisible();
    // The switch sits outside the swapped frame, so a swap keeps the same element.
    await toggle.evaluate((element) => element.setAttribute('data-kept', ''));
    await toggle.click();
    await expect(watcher).toHaveURL(/archived=1/, LIVE);
    await expect(toggle).toHaveAttribute('data-kept', '');
    await expect(watcher.locator('[data-experiment]')).toHaveCount(1);

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await actor.goto(url);
    await actor.locator('[data-experiment-row-archive]').first().click();

    await expect(watcher.locator('[data-experiment]')).toHaveCount(2, LIVE);
    await expect(watcher).toHaveURL(/archived=1/);
    await expect(
        watcher.locator('[data-experiment-archived-toggle]'),
    ).toBeChecked();
});

test('an experiment cards variant filter survives a live signal from another actor', async ({
    browser,
    request,
}) => {
    const { watcher, projectId, newCard, report } = await experimentActor(
        browser,
        request,
        'experiment-cards',
    );
    const first = await newCard('Variant a card');
    const second = await newCard('Variant b card');
    await report(first, 'frame-cards', 'alpha');
    await report(second, 'frame-cards', 'beta');

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(
        `/projects/${projectId}/analytics/experiments/frame-cards/cards`,
    );
    await expect(watcher.locator('[data-experiment-card]')).toHaveCount(2);
    await watcher
        .locator('[data-experiment-filter] a', { hasText: 'alpha' })
        .click();
    await expect(watcher).toHaveURL(/variant=alpha/, LIVE);
    await expect(watcher.locator('[data-experiment-card]')).toHaveCount(1);
    await watcher.locator('[data-experiment-card]').evaluate((row) => {
        (row as unknown as { kept: boolean }).kept = true;
    });

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    const third = await newCard('Another variant a card');
    await report(third, 'frame-cards', 'alpha');
    const fourth = await newCard('Another variant b card');
    await report(fourth, 'frame-cards', 'beta');

    await expect(watcher.locator('[data-experiment-card]')).toHaveCount(
        2,
        LIVE,
    );
    await expect(watcher).toHaveURL(/variant=alpha/);
    // The new card joins the list in place, and the card on screen keeps its element.
    expect(
        await watcher
            .locator('[data-experiment-card]')
            .evaluateAll(
                (rows) =>
                    rows.filter(
                        (row) => (row as unknown as { kept?: boolean }).kept,
                    ).length,
            ),
    ).toBe(1);
});
