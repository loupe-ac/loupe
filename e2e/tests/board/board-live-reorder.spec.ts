/**
 * Browser coverage for a block reorder on an open board: the cards show in
 * the new order with no reload, also when one listed card is held in a drag
 * while the call runs and is let go after it. The calls go through the public
 * API, and the hub delivers the live messages, so the run needs a Mercure hub
 * the browser can reach.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
    type Page,
} from '@playwright/test';
import { accessToken, signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardLiveReorder1!';
const TITLES = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'];
const CARD = '[data-board-drag-target="card"]';
const READY = '#board[data-board-drag-ready="true"]';
// Placements run one at a time, and a held card adds a wait and a manifest read.
const SETTLED = { timeout: 20000 };

interface Board {
    page: Page;
    numbers: Map<string, number>;
    reorder: (titles: string[]) => Promise<void>;
    titles: () => Promise<string[]>;
}

function nextGroup(page: Page) {
    return page.locator(
        '[data-column-slug="next"] [data-board-drag-target="group"]',
    );
}

async function openBoard(page: Page, url: string): Promise<void> {
    await page.goto(url);
    await expect(page.locator(READY)).toBeAttached();
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(page.locator('[data-board-live-connected]')).toHaveCount(1, {
        timeout: 15000,
    });
}

test.describe('a block reorder on an open board', () => {
    // Six cards go through the API and two sessions sign in.
    test.slow();

    async function arrange(
        browser: Browser,
        request: APIRequestContext,
        tag: string,
    ): Promise<Board & { boardUrl: string }> {
        const email = `e2e+livereorder+${tag}+${RUN}@example.com`;
        const registered = await request.post('/dev/register-and-verify', {
            form: {
                fullName: 'E2E Live Reorder User',
                email,
                password: PASSWORD,
            },
        });
        expect(registered.status()).toBe(200);

        const page = await signedInPage(browser, email, PASSWORD);
        const seeded = await page.request.post('/dev/seed/document', {
            form: { title: 'E2E Live Reorder Project', markdown: '# Order' },
        });
        expect(seeded.status()).toBe(201);
        const projectId = (await seeded.json()).projectId as string;
        const token = await accessToken(page, 'public');
        const headers = {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        };
        const api = `/api/v1/projects/${projectId}/cards`;

        const numbers = new Map<string, number>();
        for (const title of TITLES) {
            const created = await page.request.post(api, {
                headers,
                data: { title, body: '', type: 'feature', status: 'next' },
            });
            expect(created.status(), await created.text()).toBe(201);
            numbers.set(title, (await created.json()).number as number);
        }

        return {
            page,
            numbers,
            boardUrl: `/projects/${projectId}/board`,
            reorder: async (titles) => {
                const response = await page.request.post(`${api}/reorder`, {
                    headers,
                    data: {
                        column: 'next',
                        cards: titles.map((title) =>
                            String(numbers.get(title)),
                        ),
                        at: 'top',
                    },
                });
                expect(response.status(), await response.text()).toBe(200);
            },
            titles: () =>
                nextGroup(page)
                    .locator(CARD)
                    .evaluateAll((cards) =>
                        cards.map(
                            (card) =>
                                card.getAttribute('data-card-title') ?? '',
                        ),
                    ),
        };
    }

    test('shows the block in its new order with no reload', async ({
        browser,
        request,
    }) => {
        const board = await arrange(browser, request, 'plain');
        await openBoard(board.page, board.boardUrl);
        await expect.poll(board.titles).toEqual(TITLES);
        await board.page.evaluate(() => {
            (window as unknown as { stayed: boolean }).stayed = true;
        });

        await board.reorder(['Foxtrot', 'Delta', 'Bravo']);

        await expect
            .poll(board.titles, SETTLED)
            .toEqual(['Foxtrot', 'Delta', 'Bravo', 'Alpha', 'Charlie', 'Echo']);
        expect(
            await board.page.evaluate(
                () => (window as unknown as { stayed?: boolean }).stayed,
            ),
        ).toBe(true);

        await board.page.context().close();
    });

    test('shows the block in its new order when a listed card is held in a drag during the call', async ({
        browser,
        request,
    }) => {
        const board = await arrange(browser, request, 'held');
        const page = board.page;
        await openBoard(page, board.boardUrl);
        await expect.poll(board.titles).toEqual(TITLES);
        await page.evaluate(() => {
            (window as unknown as { stayed: boolean }).stayed = true;
        });

        const held = page.locator(`${CARD}[data-card-title="Echo"]`);
        const grip = await held.locator('.lp-board-card__title').boundingBox();
        expect(grip).not.toBeNull();
        if (grip === null) {
            return;
        }
        await page.mouse.move(
            grip.x + grip.width / 2,
            grip.y + grip.height / 2,
        );
        await page.mouse.down();
        await page.mouse.move(grip.x + grip.width / 2, grip.y + 80, {
            steps: 10,
        });
        await expect(held).toHaveClass(/lp-board-card--dragging/);

        await board.reorder(['Foxtrot', 'Echo']);

        // The unheld card of the block shows first, while the held card waits.
        await expect(nextGroup(page).locator(CARD).first()).toHaveAttribute(
            'data-card-title',
            'Foxtrot',
            SETTLED,
        );
        await page.keyboard.press('Escape');
        await page.mouse.up();

        await expect
            .poll(board.titles, SETTLED)
            .toEqual(['Foxtrot', 'Echo', 'Alpha', 'Bravo', 'Charlie', 'Delta']);
        await expect(page.locator(`${CARD}[aria-busy]`)).toHaveCount(0);
        expect(
            await page.evaluate(
                () => (window as unknown as { stayed?: boolean }).stayed,
            ),
        ).toBe(true);

        await page.context().close();
    });
});
