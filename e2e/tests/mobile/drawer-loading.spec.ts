/**
 * The card drawer on a 390px phone shows its header and close button at once,
 * while the card still loads.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const EMAIL = 'e2e-mobile-drawer@example.com';
const QUESTION = 'Which format should the export use?';
const test = createTest({
    email: EMAIL,
    password: 'E2eMobileDrawer1!',
    name: 'Mobile Drawer Reviewer',
});

test.use({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

// The flag is global, so it goes back to its shipped value for later specs.
test.afterAll(async ({ request }) => {
    await setInbox(request, false);
});

async function setInbox(
    request: Page['request'],
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function projectPath(page: Page): Promise<string> {
    const harness = await page.request.get(
        '/dev/live-review-harness?email=' + encodeURIComponent(EMAIL),
    );
    expect(harness.ok()).toBeTruthy();
    await page.goto('/');
    await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+\/documents$/);
    const id = /\/projects\/([0-9a-f-]+)\/documents$/.exec(page.url())?.[1];
    expect(id).toBeTruthy();

    return `/projects/${id}`;
}

/** Creates a card in the first column after Backlog, and returns its id. */
async function createCard(
    page: Page,
    project: string,
    title: string,
): Promise<string> {
    await page.goto(`${project}/board/cards/new`);
    await page.getByLabel('Title').fill(title);
    const column = page.getByLabel('Column');
    const labels = await column.locator('option').allTextContents();
    const label = labels
        .map((text) => text.trim())
        .find((text) => text !== 'Backlog');
    expect(label).toBeTruthy();
    await column.selectOption({ label: label ?? '' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible();

    return new URL(page.url()).pathname.split('/').pop() ?? '';
}

/** Holds each request that matches the pattern until the returned function runs. */
async function hold(page: Page, pattern: string): Promise<() => void> {
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(pattern, async (route) => {
        await held;
        await route.continue();
    });

    return release;
}

async function expectLoadingHeader(
    drawer: Locator,
    title: string,
): Promise<Locator> {
    await expect(drawer).toHaveJSProperty('open', true);
    const loading = drawer.locator('[data-card-drawer-target="loading"]');
    await expect(loading.locator('.lp-drawer__title')).toHaveText(title);
    await expect(loading.locator('.lp-drawer__loading')).toHaveText(
        'Loading card…',
    );
    await expect(
        loading.getByRole('button', { name: 'Close card' }),
    ).toBeVisible();

    return loading;
}

test('New work opens a drawer with its header before the form loads', async ({
    page,
}) => {
    await page.goto(`${await projectPath(page)}/board`);
    const release = await hold(page, '**/board/cards/new**');

    await page.getByRole('link', { name: 'New work' }).first().tap();

    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    const loading = await expectLoadingHeader(drawer, 'New work');

    release();
    await expect(drawer.getByLabel('Title', { exact: true })).toBeVisible();
    await expect(loading).toBeHidden();
});

test('a board card opens a drawer with its number before the card loads', async ({
    page,
}) => {
    const project = await projectPath(page);
    const title = `Phone drawer card ${Date.now()}`;
    const cardId = await createCard(page, project, title);
    await page.goto(`${project}/board`);
    const link = page.locator(
        `a.lp-board-card__title[href$="/board/cards/${cardId}"]`,
    );
    const number = await link.getAttribute('data-drawer-title');
    expect(number).toMatch(/^#\d+$/);
    const release = await hold(page, `**/board/cards/${cardId}**`);

    await link.tap();

    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    const loading = await expectLoadingHeader(drawer, number ?? '');

    release();
    await expect(drawer.getByRole('heading', { name: title })).toBeVisible();
    await expect(loading).toBeHidden();
});

test('an inbox ask opens a drawer with its title before the card loads', async ({
    page,
}) => {
    await setInbox(page.request, true);
    const project = await projectPath(page);
    const title = `Phone drawer ask card ${Date.now()}`;
    const cardId = await createCard(page, project, title);
    const seeded = await page.request.post('/dev/seed/inbox', {
        form: { cardId },
    });
    expect(seeded.status()).toBe(201);
    const { questionNumber } = await seeded.json();
    await page.goto(`${project}/inbox?item=${questionNumber}`);
    await page.getByRole('button', { name: 'Linked items' }).tap();
    const release = await hold(page, `**/board/cards/${cardId}**`);

    await page
        .locator('[data-inbox-linked-menu] a[data-linked-card]')
        .filter({ hasText: title })
        .tap();

    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    const loading = await expectLoadingHeader(drawer, QUESTION);

    release();
    await expect(
        drawer.getByRole('tab', { name: 'Conversation' }),
    ).toBeVisible();
    await expect(loading).toBeHidden();
});

test('the close button of the loading header closes the drawer', async ({
    page,
}) => {
    await page.goto(`${await projectPath(page)}/board`);
    await page.route(
        '**/board/cards/new**',
        () => new Promise(() => undefined),
    );

    await page.getByRole('link', { name: 'New work' }).first().tap();
    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    await expect(drawer).toHaveJSProperty('open', true);
    await drawer
        .locator('[data-card-drawer-target="loading"]')
        .getByRole('button', { name: 'Close card' })
        .tap();

    await expect(drawer).toHaveJSProperty('open', false);
});
