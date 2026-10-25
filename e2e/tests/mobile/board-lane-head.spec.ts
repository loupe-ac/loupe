/**
 * The epic lane head of the phone board at 390px. The head keeps the title, the done count and a "…"
 * button, and that button opens the lane sheet with the lane actions.
 */

import { expect, type Page } from '@playwright/test';
import {
    accessToken,
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2ePhoneLane1!';

/** Creates cards through the MCP card_create tool, which sets the type and the parent in one call. */
async function createCards(
    page: Page,
    projectId: string,
    cards: {
        title: string;
        type: string;
        parent?: number;
        status?: string;
    }[],
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
                        status: card.status ?? 'next',
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

const test = base.extend<{ projectId: string; epic: string }>({
    projectId: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+phonelane+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                { form: { fullName: 'E2E Lane', email, password: PASSWORD } },
            );
            expect(registered.status()).toBe(200);

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', { timeout: 20000 });

            const seeded = await page.request.post('/dev/seed/document', {
                form: { title: 'Phone lanes', markdown: '# Board' },
            });
            expect(seeded.status()).toBe(201);

            await use((await seeded.json()).projectId as string);
        },
        { auto: true },
    ],
    epic: async ({ page, projectId }, use) => {
        const [epic] = await createCards(page, projectId, [
            { title: 'Phone epic with a rather long name', type: 'epic' },
            { title: 'Done soon', type: 'feature', parent: 0 },
            {
                title: 'Waits in Backlog',
                type: 'feature',
                parent: 0,
                status: 'backlog',
            },
        ]);
        await use(epic);
    },
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

const lane = (page: Page, epic: string) =>
    page.locator(`.lp-board-lane[data-lane="${epic}"]`);

test('an open lane head keeps the title, the done count and the lane button', async ({
    page,
    projectId,
    epic,
}) => {
    await page.goto(`/projects/${projectId}/board`);
    const head = lane(page, epic).locator('.lp-board-lane__head');

    await expect(head.locator('.lp-board-lane__title')).toBeVisible();
    await expect(head.locator('.lp-board-lane__progress')).toContainText('/');
    await expect(head.locator('.lp-board-lane__menu')).toBeVisible();
    for (const hidden of [
        '.lp-board-lane__collapse',
        '.lp-board-lane__move',
        '.lp-board-lane__number',
        '.lp-board-lane__status',
        '.lp-board-lane__bar',
        '.lp-board-lane__done',
        '.lp-board-lane__up-next',
    ]) {
        await expect(head.locator(hidden)).toBeHidden();
    }
    const title = await head.locator('.lp-board-lane__title').boundingBox();
    expect(title!.width).toBeGreaterThan(200);
});

test('the lane sheet hides and shows the cards of the lane', async ({
    page,
    projectId,
    epic,
}) => {
    await page.goto(`/projects/${projectId}/board`);
    const menu = lane(page, epic).locator('.lp-board-lane__menu');

    await menu.tap();
    const sheet = page.getByRole('dialog');
    await expect(sheet).toContainText('Phone epic with a rather long name');
    await expect(sheet.getByRole('button', { name: 'Move #' })).toBeVisible();
    await expect(
        sheet.getByRole('button', { name: 'Hide lane' }),
    ).toBeVisible();
    await expect(
        sheet.getByRole('link', { name: 'Up next: 1 card' }),
    ).toBeVisible();
    await sheet.getByRole('button', { name: 'Hide cards' }).tap();
    await expect(sheet).toBeHidden();
    await expect(lane(page, epic)).toHaveClass(/lp-board-lane--collapsed/);

    const bar = lane(page, epic).locator('.lp-board-lane__head');
    await expect(bar.locator('.lp-board-lane__title')).toBeVisible();
    await expect(bar.locator('.lp-board-lane__progress')).toBeVisible();
    await expect(bar.locator('.lp-board-lane__menu')).toBeVisible();

    await bar.locator('.lp-board-lane__menu').tap();
    await expect(
        page.getByRole('dialog').getByRole('button', { name: 'Show cards' }),
    ).toBeVisible();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Show cards' })
        .tap();
    await expect(lane(page, epic)).not.toHaveClass(/lp-board-lane--collapsed/);
});

test('the lane sheet opens the Move sheet of the epic', async ({
    page,
    projectId,
    epic,
}) => {
    await page.goto(`/projects/${projectId}/board`);

    await lane(page, epic).locator('.lp-board-lane__menu').tap();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: /^Move #/ })
        .tap();

    await expect(
        page.getByRole('dialog', { name: /^Move #\d+ to$/ }),
    ).toBeVisible();
});

test('the lane sheet shows no Up next row for an epic with no deck', async ({
    page,
    projectId,
}) => {
    const [bare] = await createCards(page, projectId, [
        { title: 'Phone epic with no deck', type: 'epic' },
        { title: 'Already chosen', type: 'feature', parent: 0 },
    ]);
    await page.goto(`/projects/${projectId}/board`);

    await lane(page, bare).locator('.lp-board-lane__menu').tap();
    const sheet = page.getByRole('dialog');
    await expect(sheet).toContainText('Phone epic with no deck');
    await expect(
        sheet.locator('[data-lane-sheet-target="upNextRow"]'),
    ).toBeHidden();
});
