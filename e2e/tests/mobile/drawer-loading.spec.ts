/**
 * The card drawer on a 390px phone shows its header and close button at once,
 * while the card still loads.
 */

import { expect, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const EMAIL = 'e2e-mobile-drawer@example.com';
const test = createTest({
    email: EMAIL,
    password: 'E2eMobileDrawer1!',
    name: 'Mobile Drawer Reviewer',
});

test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

async function boardUrl(page: Page): Promise<string> {
    const harness = await page.request.get(
        '/dev/live-review-harness?email=' + encodeURIComponent(EMAIL),
    );
    expect(harness.ok()).toBeTruthy();
    await page.goto('/');
    await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+\/documents$/);
    const id = /\/projects\/([0-9a-f-]+)\/documents$/.exec(page.url())?.[1];
    expect(id).toBeTruthy();

    return `/projects/${id}/board`;
}

test('New work opens a drawer with its header before the form loads', async ({
    page,
}) => {
    await page.goto(await boardUrl(page));
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/board/cards/new**', async (route) => {
        await held;
        await route.continue();
    });

    await page.getByRole('link', { name: 'New work' }).first().tap();

    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    await expect(drawer).toHaveJSProperty('open', true);
    const loading = drawer.locator('[data-card-drawer-target="loading"]');
    await expect(loading.locator('.lp-drawer__title')).toHaveText('New work');
    await expect(loading.locator('.lp-drawer__loading')).toHaveText(
        'Loading card…',
    );
    await expect(
        loading.getByRole('button', { name: 'Close card' }),
    ).toBeVisible();

    release();
    await expect(drawer.getByLabel('Title', { exact: true })).toBeVisible();
    await expect(loading).toBeHidden();
});

test('the close button of the loading header closes the drawer', async ({
    page,
}) => {
    await page.goto(await boardUrl(page));
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
