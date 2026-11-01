/**
 * Browser coverage for the project inbox: the owner answers a question by
 * picking an option, which the answer controller copies into the hidden field,
 * and declines a to-do. Both are read back after the redirect.
 */

import { expect, type APIRequestContext, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eInboxAnswers1!';

async function setInboxFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Inbox User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

interface Inbox {
    inboxUrl: string;
    questionNumber: number;
    todoNumber: number;
}

const test = base.extend<{ inbox: Inbox }>({
    inbox: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);
            await setInboxFlag(page.request, true);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            await registerAndLogin(page, `e2e+inbox+${tag}+${RUN}@example.com`);

            const response = await page.request.post('/dev/seed/inbox');
            expect(response.status()).toBe(201);
            const seeded = await response.json();

            const inboxUrl = `/projects/${seeded.projectId}/inbox`;
            await page.goto(inboxUrl);

            await use({
                inboxUrl,
                questionNumber: seeded.questionNumber,
                todoNumber: seeded.todoNumber,
            });
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setInboxFlag(request, false);
});

test('the inbox top bar holds no activity link and never overflows', async ({
    page,
}) => {
    // The page draws no header of its own, so its top bar holds the crumbs alone.
    await expect(page.locator('main .lp-page-header')).toHaveCount(0);
    const topbar = page.locator('.lp-topbar');
    await expect(
        topbar.getByRole('link', { name: 'View activity', exact: true }),
    ).toHaveCount(0);
    await expect(
        topbar.getByRole('link', { name: 'Settings', exact: true }),
    ).toHaveCount(0);
    for (const width of [1440, 950, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(
            await topbar.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        ).toBeLessThanOrEqual(1);
    }
});

test('projects header keeps its action reachable with enlarged text', async ({
    page,
}, testInfo) => {
    await page.goto('/projects');
    const header = page.locator('.lp-page-header');
    const action = header.getByRole('button', {
        name: 'New project',
        exact: true,
    });
    await expect(action).toHaveCount(1);
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.screenshot({
                path: testInfo.outputPath(`projects-${width}-${fontSize}.png`),
                animations: 'disabled',
            });
            await expect(action).toBeInViewport({ ratio: 1 });
            const copyBounds = await header
                .locator('.lp-page-header__copy')
                .boundingBox();
            const descriptionBounds = await header
                .locator('.lp-workspace-desc')
                .boundingBox();
            expect(copyBounds).not.toBeNull();
            expect(descriptionBounds).not.toBeNull();
            expect(
                copyBounds!.y +
                    copyBounds!.height -
                    descriptionBounds!.y -
                    descriptionBounds!.height,
            ).toBeLessThanOrEqual(1);
            expect(
                await header.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            ).toBeLessThanOrEqual(1);
            expect(
                await action.evaluate((element) => {
                    const bounds = element.getBoundingClientRect();
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    const content = range.getBoundingClientRect();
                    return Math.max(
                        bounds.left - content.left,
                        content.right - bounds.right,
                    );
                }),
            ).toBeLessThanOrEqual(1);
        }
    }
    await action.focus();
    await expect(action).toBeFocused();
    await action.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'New project' });
    await expect(dialog).toBeVisible();
    await expect(
        dialog.getByLabel('Project name', { exact: true }),
    ).toBeFocused();
});

test('the list head keeps the switch, the search and the type filter in reach', async ({
    page,
}) => {
    const head = page.locator('.lp-inbox-list-head');
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(head).toBeVisible();
        await expect(head.locator('.lp-tabs__tab')).toHaveCount(2);
        await expect(head.locator('#inbox-search')).toBeVisible();
        await expect(head.locator('[data-inbox-type]')).toHaveCount(6);
        expect(
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            ),
        ).toBeLessThanOrEqual(1);
    }
});

test('inbox search shows Clear only while a search is active', async ({
    page,
}) => {
    const search = page.locator('#inbox-search');
    const clear = page.locator('.lp-filter-clear');
    const head = page.locator('.lp-inbox-list-head');
    async function verifyLayout(): Promise<void> {
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await expect(search).toBeVisible();
            await expect(clear).toBeVisible();
            const searchBox = await search.boundingBox();
            const clearBox = await clear.boundingBox();
            const headBox = await head.boundingBox();
            expect(searchBox).not.toBeNull();
            expect(clearBox).not.toBeNull();
            expect(headBox).not.toBeNull();
            expect(clearBox!.x + clearBox!.width).toBeLessThanOrEqual(
                headBox!.x + headBox!.width,
            );
            for (const count of await page.locator('.lp-filter-count').all()) {
                const countBox = await count.boundingBox();
                expect(countBox).not.toBeNull();
                expect(countBox!.x + countBox!.width).toBeLessThanOrEqual(
                    headBox!.x + headBox!.width,
                );
            }
        }
    }
    await expect(clear).toHaveCount(0);
    await search.fill('unmatched-inbox-query');
    await expect(page.locator('[data-inbox-search-empty]')).toBeVisible();
    await expect(page.locator('.lp-filter-count')).toHaveText('No ask matches');
    await verifyLayout();
    await clear.click();
    await expect(search).toHaveValue('');
    await expect(
        page.locator('[data-inbox-section="open-asks"]'),
    ).toBeVisible();
});

test('the note field marks focus at one height', async ({ page, inbox }) => {
    const field = page
        .locator(`#inbox-item-${inbox.questionNumber}`)
        .getByLabel('Note for the agent');
    await expect(field).toBeVisible();
    const before = await field.evaluate((element) => ({
        height: element.offsetHeight,
        shadow: getComputedStyle(element).boxShadow,
    }));

    await field.focus();

    await expect
        .poll(() =>
            field.evaluate((element) => getComputedStyle(element).boxShadow),
        )
        .not.toBe(before.shadow);
    expect(await field.evaluate((element) => element.offsetHeight)).toBe(
        before.height,
    );
});

test('the loading cover hides the reply card and ends at the content edge', async ({
    page,
    inbox,
}) => {
    await page.setViewportSize({ width: 2400, height: 1000 });
    const replyCard = page
        .locator(`#inbox-item-${inbox.questionNumber} .lp-comment-composer`)
        .first();
    await expect(replyCard).toBeVisible();
    const contentEdge = await page
        .locator('.lp-inbox-request-detail')
        .evaluate((pane) => {
            const box = pane.getBoundingClientRect();
            return box.right - parseFloat(getComputedStyle(pane).paddingRight);
        });

    // The held response keeps the pane loading for the whole check.
    let release = (): void => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(/\/inbox\?/, async (route) => {
        await held;
        await route.continue();
    });
    await page.locator('a.lp-list-row').first().click();

    const cover = page.locator('.lp-inbox-pane-loading');
    await expect(cover).toBeVisible();
    await expect
        .poll(() =>
            replyCard.evaluate((card) => {
                const box = card.getBoundingClientRect();
                const top = document.elementFromPoint(
                    box.left + box.width / 2,
                    box.top + box.height / 2,
                );
                return Boolean(top?.closest('.lp-inbox-pane-loading'));
            }),
        )
        .toBe(true);
    const coverEdge = await cover.evaluate(
        (element) => element.getBoundingClientRect().right,
    );
    expect(Math.abs(coverEdge - contentEdge)).toBeLessThan(1);

    release();
});

test('the owner answers a question and declines a to-do', async ({
    page,
    inbox,
}) => {
    const sidebarPill = page.locator('[data-inbox-open-count]');
    await expect(sidebarPill).toHaveText('2');

    const question = page.locator(`#inbox-item-${inbox.questionNumber}`);
    await expect(question).toContainText('Which format should the export use?');
    await question.getByText('CSV', { exact: true }).click();
    await question
        .getByLabel('Note for the agent')
        .fill('The importer reads CSV.');
    await question.getByRole('button', { name: 'Send answer' }).click();

    await expect(page.locator('[data-inbox-status]')).toContainText(
        'Answer sent. 1 item of this ask is still open.',
    );
    // The picked option only reaches the server through the answer controller.
    await expect(question.locator('[data-inbox-choices] li')).toHaveText([
        'CSV',
    ]);
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'The importer reads CSV.',
    );
    // A sent answer is final, so the question keeps no form to change it.
    await expect(question.locator('.lp-comment-composer')).toHaveCount(0);
    await expect(question.locator('form')).toHaveCount(0);
    await expect(sidebarPill).toHaveText('1');

    // Dismiss closes the to-do, with the note field left empty.
    const todo = page.locator(`#inbox-item-${inbox.todoNumber}`);
    await todo.getByRole('button', { name: 'Dismiss' }).click();

    // No other ask is open, so the page names none and the status line links to the closed one.
    await expect(page.locator('[data-inbox-status]')).toContainText(
        'is closed.',
    );
    await expect(
        page.locator('[data-inbox-queue-empty="open-asks"]'),
    ).toBeVisible();
    await page.getByRole('link', { name: 'View it' }).click();
    await expect(todo).toContainText('dismissed it at');
    await expect(sidebarPill).toHaveCount(0);

    await page.reload();
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'The importer reads CSV.',
    );
    await expect(question.locator('.lp-comment-composer')).toHaveCount(0);
});
