/**
 * Browser coverage for the inbox view that the address describes: a row click,
 * the type filter and the switch each reload one frame and move the address,
 * an old fragment link becomes an item query, and at phone width the list and
 * the ask take turns.
 */

import { expect, type APIRequestContext, type Page } from '@playwright/test';
import {
    hubStubbedTest as test,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eInboxView1!';

async function setInboxFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

interface Seeded {
    projectId: string;
    askId: string;
    questionNumber: number;
    todoNumber: number;
}

async function seedTwoAsks(
    page: Page,
    tag: string,
): Promise<{ first: Seeded; second: Seeded }> {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setInboxFlag(page.request, true);
    const email = `e2e+inbox+view+${tag}+${RUN}@example.com`;
    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Inbox View', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });

    const first = await (await page.request.post('/dev/seed/inbox')).json();
    const second = await (await page.request.post('/dev/seed/inbox')).json();
    return { first, second };
}

/**
 * A frame that advances the address ends in a page visit, which caches the
 * old page one repaint after its turbo:load. A Back before that cache aborts.
 */
async function watchAdvance(page: Page): Promise<() => Promise<void>> {
    await page.evaluate(() => {
        (window as unknown as { advanced: Promise<void> }).advanced =
            new Promise((resolve) => {
                document.addEventListener(
                    'turbo:load',
                    () =>
                        requestAnimationFrame(() =>
                            requestAnimationFrame(() => resolve()),
                        ),
                    { once: true },
                );
            });
    });

    return async () => {
        await page.evaluate(
            () => (window as unknown as { advanced: Promise<void> }).advanced,
        );
    };
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setInboxFlag(request, false);
});

test('a row click moves the address without a page load, and back returns', async ({
    page,
}, testInfo) => {
    const { first, second } = await seedTwoAsks(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );
    const inboxUrl = `/projects/${first.projectId}/inbox`;
    await page.goto(inboxUrl);

    await expect(page.locator('a.lp-list-row')).toHaveCount(2);
    await expect(page.locator('[data-inbox-ask-id]')).toHaveCount(1);
    await page.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });
    const advanced = await watchAdvance(page);

    await page
        .locator(`a.lp-list-row[data-inbox-request="${second.askId}"]`)
        .click();
    await expect(page).toHaveURL(`${inboxUrl}?ask=${second.askId}`);
    await expect(
        page.locator(`[data-inbox-ask-id="${second.askId}"]`),
    ).toBeVisible();
    await expect(
        page.locator(`a.lp-list-row[data-inbox-request="${second.askId}"]`),
    ).toHaveAttribute('aria-current', 'true');
    expect(
        await page.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await advanced();
    await page.goBack();
    await expect(page).toHaveURL(inboxUrl);
    await expect(
        page.locator(`[data-inbox-ask-id="${first.askId}"]`),
    ).toBeVisible();

    await page.reload();
    await page.goto(`${inboxUrl}?ask=${second.askId}`);
    await expect(
        page.locator(`[data-inbox-ask-id="${second.askId}"]`),
    ).toBeVisible();
});

test('the type filter and the switch change the list and keep the address', async ({
    page,
}, testInfo) => {
    const { first } = await seedTwoAsks(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );
    const inboxUrl = `/projects/${first.projectId}/inbox`;
    await page.goto(inboxUrl);

    await page.locator('[data-inbox-type="todo"]').click();
    await expect(page).toHaveURL(`${inboxUrl}?type=todo`);
    await expect(page.locator('a.lp-list-row')).toHaveCount(2);

    await page.locator('[data-inbox-type="review"]').click();
    await expect(page).toHaveURL(`${inboxUrl}?type=review`);
    await expect(page.locator('a.lp-list-row')).toHaveCount(0);
    await expect(page.locator('[data-inbox-type-empty]')).toBeVisible();

    await page.locator('[data-inbox-side="closed"]').click();
    await expect(page).toHaveURL(`${inboxUrl}?side=closed&type=review`);
    await expect(page.locator('[data-inbox-type-empty]')).toBeVisible();

    await page.locator('[data-inbox-type="all"]').click();
    await expect(page).toHaveURL(`${inboxUrl}?side=closed`);
    await expect(
        page.locator('[data-inbox-queue-empty="closed-asks"]'),
    ).toContainText('No ask is closed yet.');

    await page.locator('[data-inbox-side="open"]').click();
    await expect(page.locator('a.lp-list-row')).toHaveCount(2);
});

test('the view sits straight on a white page, with no frame or fill', async ({
    page,
}, testInfo) => {
    const { first } = await seedTwoAsks(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );
    await page.goto(`/projects/${first.projectId}/inbox`);
    await expect(page.locator('[data-inbox-ask-id]')).toHaveCount(1);

    const white = 'rgb(255, 255, 255)';
    const clear = 'rgba(0, 0, 0, 0)';
    await expect(page.locator('.lp-main')).toHaveCSS('background-color', white);
    for (const selector of [
        '.lp-inbox-section--queue',
        '.lp-inbox-request-detail',
        '.lp-inbox-ask',
    ]) {
        const element = page.locator(selector);
        await expect(element).toHaveCSS('background-color', clear);
        await expect(element).toHaveCSS('border-top-width', '0px');
    }
    for (const selector of ['.lp-inbox-section--queue', '.lp-inbox-ask']) {
        await expect(page.locator(selector)).toHaveCSS('padding-top', '0px');
    }
    // Each pane scrolls on its own, so the page's top padding sits inside the panes, the same on both.
    await expect(page.locator('.lp-workspace')).toHaveCSS('padding-top', '0px');
    const listPadding = await page
        .locator('.lp-inbox-request-list')
        .evaluate((element) => getComputedStyle(element).paddingTop);
    await expect(page.locator('.lp-inbox-request-detail')).toHaveCSS(
        'padding-top',
        listPadding,
    );

    const tint = 'rgb(247, 248, 243)';
    for (const selector of ['.lp-decision--inbox']) {
        await expect(page.locator(selector).first()).toHaveCSS(
            'background-color',
            tint,
        );
    }
});

test('an old fragment link opens the ask of the item', async ({
    page,
}, testInfo) => {
    const { first, second } = await seedTwoAsks(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );
    const inboxUrl = `/projects/${first.projectId}/inbox`;

    await page.goto(`${inboxUrl}#inbox-item-${second.todoNumber}`);

    await expect(page).toHaveURL(`${inboxUrl}?item=${second.todoNumber}`);
    await expect(
        page.locator(`[data-inbox-ask-id="${second.askId}"]`),
    ).toBeVisible();
    await expect(
        page.locator(`#inbox-item-${second.todoNumber}`),
    ).toBeVisible();
});

test('at phone width the list and the ask take turns', async ({
    page,
}, testInfo) => {
    const { first, second } = await seedTwoAsks(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );
    await page.setViewportSize({ width: 390, height: 900 });
    const inboxUrl = `/projects/${first.projectId}/inbox`;
    await page.goto(inboxUrl);

    await expect(page.locator('.lp-inbox-request-list')).toBeVisible();
    await expect(page.locator('.lp-inbox-request-detail')).toBeHidden();

    await page
        .locator(`a.lp-list-row[data-inbox-request="${second.askId}"]`)
        .click();
    await expect(page).toHaveURL(`${inboxUrl}?ask=${second.askId}`);
    await expect(page.locator('.lp-inbox-request-list')).toBeHidden();
    await expect(
        page.locator(`[data-inbox-ask-id="${second.askId}"]`),
    ).toBeVisible();

    await page.getByRole('link', { name: 'All asks' }).click();
    await expect(page).toHaveURL(inboxUrl);
    await expect(page.locator('.lp-inbox-request-list')).toBeVisible();
    await expect(page.locator('.lp-inbox-request-detail')).toBeHidden();
});
