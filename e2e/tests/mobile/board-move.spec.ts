/**
 * The Move menu of the phone board at 390px. The "…" button of a card opens a
 * sheet that lists the Backlog and each column. A pick moves the card, closes
 * the sheet and shows a message with a Show action. A managed card asks first.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
// A move is a POST and a placement, which take longer than the 5 s default under a loaded run.
const ROUND_TRIP = { timeout: 20_000 };
const PASSWORD = 'E2ePhoneMove1!';
const COLUMNS = [
    'Next',
    'Product design',
    'Tech design',
    'Implementation',
    'In review',
    'Done',
];

async function createCard(
    page: Page,
    projectId: string,
    title: string,
): Promise<void> {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title', { exact: true }).fill(title);
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible({ timeout: 15_000 });
}

const test = base.extend<{ boardUrl: string }>({
    boardUrl: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+phonemove+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                { form: { fullName: 'E2E Move', email, password: PASSWORD } },
            );
            expect(registered.status()).toBe(200);

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', { timeout: 20000 });

            // A project made in the app gets the lifecycle workflow, so its cards are managed.
            await page.goto('/projects');
            await page
                .locator('.lp-page-header')
                .getByRole('button', { name: /new project/i })
                .click();
            await page.getByLabel('Project name').fill(`move-${RUN}-${tag}`);
            await page.getByRole('button', { name: 'Add project' }).click();
            await expect(page.locator('[data-workshop]')).toBeVisible({
                timeout: 30_000,
            });
            const projectId = /\/projects\/([0-9a-f-]{36})/.exec(
                page.url(),
            )?.[1];
            expect(projectId).toBeTruthy();
            await createCard(page, projectId!, 'Move me');

            await use(`/projects/${projectId}/board`);
        },
        { auto: true },
    ],
});

test.slow();

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

const card = (page: Page): Locator =>
    page.locator('article.lp-board-card', { hasText: 'Move me' });
const moveButton = (page: Page): Locator =>
    card(page).getByRole('button', { name: /^Move #\d+$/ });
const sheet = (page: Page): Locator =>
    page.getByRole('dialog', { name: /^Move #\d+ to$/ });

async function number(page: Page): Promise<string> {
    const label = await moveButton(page).getAttribute('aria-label');

    return /#(\d+)/.exec(label ?? '')![1];
}

/** The column fills the strip, as the board columns spec reads it. */
async function expectInView(page: Page, label: string): Promise<void> {
    const column = page.locator('section.lp-board__column', {
        has: page.getByRole('heading', { name: label, exact: true }),
    });
    await expect
        .poll(
            () =>
                column.evaluate((element) => {
                    const box = element.getBoundingClientRect();
                    const strip = element
                        .closest('.lp-board__columns')!
                        .getBoundingClientRect();

                    return Math.abs(box.left - strip.left) < 2;
                }),
            { timeout: 10_000 },
        )
        .toBe(true);
}

test('a card shows a Move button of 44 px', async ({ page, boardUrl }) => {
    await page.goto(boardUrl);

    await expect(moveButton(page)).toBeVisible();
    const box = (await moveButton(page).boundingBox())!;
    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);
});

test('the Move button lists the Backlog and every column, and marks the current one', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    const cardNumber = await number(page);
    await moveButton(page).tap();

    await expect(sheet(page)).toBeVisible();
    await expect(sheet(page)).toHaveAccessibleName(`Move #${cardNumber} to`);
    await expect(sheet(page).locator('.lp-move-sheet__card-title')).toHaveText(
        'Move me',
    );
    const rows = sheet(page).locator('.lp-move-sheet__row');
    await expect(rows).toHaveCount(COLUMNS.length + 1);
    await expect(rows.first()).toContainText('Backlog');
    for (const [index, label] of COLUMNS.entries()) {
        await expect(rows.nth(index + 1)).toContainText(label);
        await expect(
            rows.nth(index + 1).locator('.lp-move-sheet__label'),
        ).toHaveText(label);
    }
    await expect(sheet(page).locator('[aria-current="true"]')).toHaveCount(1);
    await expect(sheet(page).locator('[aria-current="true"]')).toContainText(
        'Next',
    );
    const row = await rows.nth(1).boundingBox();
    expect(row!.height).toBeGreaterThanOrEqual(48);
});

test('a pick moves the card, closes the sheet and shows a message whose Show brings the column into view', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    const cardNumber = await number(page);
    await moveButton(page).tap();
    await sheet(page)
        .getByRole('button', { name: /^Tech design/ })
        .tap();

    await expect(sheet(page)).toBeHidden(ROUND_TRIP);
    const flash = page.locator('.lp-flash', {
        hasText: `Moved #${cardNumber} to Tech design`,
    });
    await expect(flash).toBeVisible(ROUND_TRIP);
    await expect(
        page.locator(
            '#board-group-' +
                (await page
                    .locator('section.lp-board__column', {
                        has: page.getByRole('heading', {
                            name: 'Tech design',
                            exact: true,
                        }),
                    })
                    .getAttribute('data-column-id')) +
                ' article.lp-board-card',
        ),
    ).toContainText('Move me');

    await flash.getByRole('button', { name: 'Show' }).tap();
    await expectInView(page, 'Tech design');
    await expect(
        page.getByRole('tab', { name: /^Tech design/ }),
    ).toHaveAttribute('aria-selected', 'true');
});

test('a managed card asks first, and Make unmanaged and move moves it', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    const cardNumber = await number(page);
    await moveButton(page).tap();
    await sheet(page)
        .getByRole('button', { name: /^Implementation/ })
        .tap();

    await expect(
        sheet(page).getByText(
            'This card is managed. Make it unmanaged and move it to Implementation?',
        ),
    ).toBeVisible(ROUND_TRIP);
    await expect(
        sheet(page).getByRole('button', { name: 'Keep it here' }),
    ).toBeVisible();
    await sheet(page)
        .getByRole('button', { name: 'Make unmanaged and move' })
        .tap();

    await expect(sheet(page)).toBeHidden(ROUND_TRIP);
    await expect(
        page.locator('.lp-flash', {
            hasText: `Moved #${cardNumber} to Implementation`,
        }),
    ).toBeVisible(ROUND_TRIP);
});

test('Keep it here closes the sheet and leaves the card where it is', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    await moveButton(page).tap();
    await sheet(page)
        .getByRole('button', { name: /^Implementation/ })
        .tap();
    await sheet(page).getByRole('button', { name: 'Keep it here' }).tap();

    await expect(sheet(page)).toBeHidden(ROUND_TRIP);
    await expect(page.locator('.lp-flash')).toHaveCount(0);
    await expect(
        page.locator('#board section.lp-board__column', {
            has: page.getByRole('heading', { name: 'Next', exact: true }),
        }),
    ).toContainText('Move me');
});
