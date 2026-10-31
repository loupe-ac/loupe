/**
 * The phone board at 390px: one column per screen, a sideways swipe steps a column,
 * and the tab strip above names each column. The "‹" and "›" arrows step the
 * board one column, and each hides at its end. The board has six columns.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    accessToken,
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { addBoardColumn } from '../helpers';

const RUN = Date.now();
const PASSWORD = 'E2ePhoneBoard1!';

const COLUMN = 'section.lp-board__column';
const EXTRA_COLUMNS = ['Review', 'QA', 'Shipped'];

/** Creates cards through the MCP card_create tool, which sets the type and the parent in one call. */
async function createCards(
    page: Page,
    projectId: string,
    cards: { title: string; type: string; parent?: number }[],
): Promise<string[]> {
    const token = await accessToken(page, 'mcp', projectId);
    const headers: Record<string, string> = {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
    };
    const initialize = await page.request.post('/mcp', {
        headers,
        data: {
            jsonrpc: '2.0',
            id: 1,
            method: 'initialize',
            params: {
                protocolVersion: '2024-11-05',
                capabilities: {},
                clientInfo: { name: 'e2e', version: '1' },
            },
        },
    });
    expect(initialize.ok()).toBeTruthy();
    headers['Mcp-Session-Id'] = initialize.headers()['mcp-session-id'] ?? '';
    await page.request.post('/mcp', {
        headers,
        data: { jsonrpc: '2.0', method: 'notifications/initialized' },
    });

    const ids: string[] = [];
    for (const [index, card] of cards.entries()) {
        const response = await page.request.post('/mcp', {
            headers,
            data: {
                jsonrpc: '2.0',
                id: index + 2,
                method: 'tools/call',
                params: {
                    name: 'card_create',
                    arguments: {
                        title: card.title,
                        body: '',
                        type: card.type,
                        status: 'next',
                        ...(undefined === card.parent
                            ? {}
                            : { parentCardId: ids[card.parent] }),
                    },
                },
            },
        });
        const answer = await response.json();
        expect(answer.result?.isError, JSON.stringify(answer)).toBeFalsy();
        ids.push(answer.result.structuredContent.cardId as string);
    }

    return ids;
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
                await addBoardColumn(page, projectId, label);
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

/** The column fills the strip: the page keeps its side padding, so the strip is the screen the column fills. */
async function expectInView(
    page: Page,
    index: number,
    columns: Locator = page.locator(COLUMN),
): Promise<void> {
    await expect
        .poll(
            () =>
                columns.nth(index).evaluate((column) => {
                    const box = column.getBoundingClientRect();
                    const strip = column
                        .closest('.lp-board__columns')!
                        .getBoundingClientRect();

                    return (
                        Math.abs(box.left - strip.left) < 2 &&
                        Math.abs(box.width - strip.width) < 2
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

test('the board menu holds Backlog and Board settings', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    const more = page.locator('.lp-board-toolbar__more');
    await expect(page.locator('a.lp-board-backlog')).toBeHidden();

    await more.click();
    const menu = page.locator('.lp-board-toolbar__menu .lp-menu__panel');
    await expect(menu.getByRole('link', { name: /^Backlog/ })).toBeVisible();
    await expect(
        menu.getByRole('link', { name: 'Board settings', exact: true }),
    ).toBeVisible();
    await expect(
        menu.getByRole('button', { name: 'Search', exact: true }),
    ).toHaveCount(0);
});

async function swipe(
    page: Page,
    from: { x: number; y: number },
    to: { x: number; y: number },
): Promise<void> {
    const session = await page.context().newCDPSession(page);
    const send = (type: string, point?: { x: number; y: number }) =>
        session.send('Input.dispatchTouchEvent', {
            type,
            touchPoints: point ? [point] : [],
        });
    await send('touchStart', from);
    for (let part = 1; part <= 6; part++) {
        await send('touchMove', {
            x: from.x + ((to.x - from.x) * part) / 6,
            y: from.y + ((to.y - from.y) * part) / 6,
        });
    }
    await send('touchEnd');
    await session.detach();
}

test('a sideways swipe steps one column on release and the tab follows it', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    await swipe(page, { x: 320, y: 500 }, { x: 120, y: 510 });

    await expectInView(page, 1);
    await expect(page.getByRole('tab').nth(1)).toHaveAttribute(
        'aria-selected',
        'true',
    );

    await swipe(page, { x: 120, y: 500 }, { x: 320, y: 490 });
    await expectInView(page, 0);
    await expect(page.getByRole('tab').first()).toHaveAttribute(
        'aria-selected',
        'true',
    );
});

test('a mostly vertical swipe leaves the column in view', async ({
    page,
    boardUrl,
}) => {
    await page.goto(boardUrl);
    await swipe(page, { x: 250, y: 600 }, { x: 190, y: 400 });

    await expectInView(page, 0);
    await expect(page.getByRole('tab').first()).toHaveAttribute(
        'aria-selected',
        'true',
    );
});

test('the arrows step the shown lane when the first epic lane is collapsed', async ({
    page,
    boardUrl,
}) => {
    const projectId = boardUrl.split('/')[2];
    const [epic] = await createCards(page, projectId, [
        { title: 'Phone epic', type: 'epic' },
        { title: 'Phone child', type: 'feature', parent: 0 },
    ]);
    await page.goto(boardUrl);
    await page.evaluate(
        ([project, epicId]) =>
            window.localStorage.setItem(
                `loupe.board.collapsed-lanes.${project}`,
                JSON.stringify([epicId]),
            ),
        [projectId, epic],
    );
    await page.reload();
    const epicLane = page.locator(`.lp-board-lane[data-lane="${epic}"]`);
    await expect(epicLane).toHaveClass(/lp-board-lane--collapsed/);
    const shown = page.locator(`.lp-board-lane[data-lane="other"] ${COLUMN}`);

    await page.getByRole('button', { name: 'Scroll forward' }).tap();
    await expectInView(page, 1, shown);
    await expect(page.getByRole('tab').nth(1)).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await tab(page, 'Shipped').tap();
    await expectInView(page, 5, shown);
    await expect(
        page.getByRole('button', { name: 'Scroll forward' }),
    ).toBeHidden();
});
