/**
 * The phone board at 390px: one column per screen, a swipe stops on a column,
 * and the tab strip above names each column. The "‹" and "›" arrows step the
 * board one column, and each hides at its end. The board has six columns.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2ePhoneBoard1!';

const COLUMN = 'section.lp-board__column';
const EXTRA_COLUMNS = ['Review', 'QA', 'Shipped'];

async function addColumn(
    page: Page,
    projectId: string,
    label: string,
): Promise<void> {
    await page.goto(`/projects/${projectId}/settings/columns`);
    const settings = page.locator('[data-board-column-settings]');
    await settings
        .getByRole('button', { name: 'Add a column', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox').fill(label);
    await dialog
        .getByRole('button', { name: 'Add column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: label, exact: true }),
    ).toBeVisible({ timeout: 15_000 });
}

const test = base.extend<{ boardUrl: string }>({
    boardUrl: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+phoneboard+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                { form: { fullName: 'E2E Phone', email, password: PASSWORD } },
            );
            expect(registered.status()).toBe(200);

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', { timeout: 20000 });

            const seeded = await page.request.post('/dev/seed/document', {
                form: { title: 'Phone board', markdown: '# Board' },
            });
            expect(seeded.status()).toBe(201);
            const projectId = (await seeded.json()).projectId as string;

            for (const label of EXTRA_COLUMNS) {
                await addColumn(page, projectId, label);
            }

            await use(`/projects/${projectId}/board`);
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

const tab = (page: Page, name: string): Locator =>
    page.getByRole('tab', { name: new RegExp(`^${name}`) });

async function expectInView(page: Page, index: number): Promise<void> {
    await expect
        .poll(
            () =>
                page
                    .locator(COLUMN)
                    .nth(index)
                    .evaluate((column) => {
                        const box = column.getBoundingClientRect();

                        return (
                            Math.abs(box.left) < 4 &&
                            Math.abs(box.width - window.innerWidth) < 40
                        );
                    }),
            { timeout: 10_000 },
        )
        .toBe(true);
}

test('the first column shows with its tab active and only the next arrow', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    await expect(page.locator(COLUMN)).toHaveCount(6);
    await expect(page.getByRole('tab')).toHaveCount(6);

    await expect(page.getByRole('tab').first()).toHaveAttribute(
        'aria-selected',
        'true',
    );
    const arrows = page.locator('.lp-board-tabs .lp-overflow-cue__arrow');
    await expect(arrows.nth(0)).toBeHidden();
    await expect(arrows.nth(1)).toBeVisible();
    await expectInView(page, 0);
});

test('the next arrow moves the board one column and the tab follows', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    const next = page.getByRole('button', { name: 'Scroll forward' });
    const prev = page.getByRole('button', { name: 'Scroll back' });

    await next.tap();
    await expectInView(page, 1);
    await expect(page.getByRole('tab').nth(1)).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByRole('tab').nth(1)).toBeInViewport();
    await expect(prev).toBeVisible();
});

test('the last tab shows the last column and hides the next arrow', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    await tab(page, 'Shipped').tap();

    await expectInView(page, 5);
    await expect(tab(page, 'Shipped')).toHaveAttribute('aria-selected', 'true');
    await expect(
        page.getByRole('button', { name: 'Scroll forward' }),
    ).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Scroll back' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Scroll back' }).tap();
    await expectInView(page, 4);
    await expect(page.getByRole('tab').nth(4)).toHaveAttribute(
        'aria-selected',
        'true',
    );
});

test('a swipe stops on a column and the tab follows it', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    const strip = page.locator('.lp-board__columns');
    await strip.evaluate((element) => {
        element.scrollTo({ left: element.clientWidth * 2 - 90 });
    });

    await expectInView(page, 2);
    await expect(page.getByRole('tab').nth(2)).toHaveAttribute(
        'aria-selected',
        'true',
    );
});
