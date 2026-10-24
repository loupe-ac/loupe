/**
 * The Comments sheet of the review bar.
 *
 * The bar sits at the foot of the review page below lg. Its Comments button
 * opens a sheet with one row per thread. Every test drives its own user and
 * document through the dev-only endpoints. The posting helpers use the
 * Selection API, as a finger does.
 */

import { expect, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eCommentsSheet1!';
const AUTHOR = 'E2E Comments Sheet';

const PHRASE = 'the phrase this thread is about';
const COMMENT_BODY = 'Please say more about this phrase.';
const MARKDOWN = `# Sheet plan

A first paragraph that holds ${PHRASE} near the top of the page.

${'A paragraph that fills the screen.\n\n'.repeat(30)}The last paragraph.
`;

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';
const SHEET_ROW = '#sheet-comment-rows .lp-comment-row';

interface Seeded {
    projectId: string;
    documentId: string;
}

function reviewPath(seeded: Seeded): string {
    return `/projects/${seeded.projectId}/documents/${seeded.documentId}/review`;
}

async function selectByTouch(page: Page, phrase: string): Promise<void> {
    await page.evaluate((wanted: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!docEl) throw new Error('doc target not found');
        const walker = document.createTreeWalker(docEl, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(wanted) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + wanted.length);
                const selection = window.getSelection();
                if (!selection) throw new Error('No selection object');
                selection.removeAllRanges();
                selection.addRange(range);

                return;
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error(`Phrase "${wanted}" not found in the doc`);
    }, phrase);
}

async function postComment(page: Page): Promise<void> {
    await selectByTouch(page, PHRASE);
    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.locator(COMPOSER_BODY).fill(COMMENT_BODY);
    await page.getByRole('button', { name: 'Post' }).click();
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
    await expect(page.locator('.lp-comment-thread')).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });
}

/**
 * Submits the reply form of the first thread through Turbo. The open sheet is
 * modal, so a reader cannot reach the card, and a reply has no live broadcast
 * to other sessions. Submitting the form from a script puts the reply stream
 * where a reply would arrive while the sheet is open.
 */
async function replyBehindTheSheet(page: Page, body: string): Promise<void> {
    await page.evaluate((text: string) => {
        const form = document.querySelector<HTMLFormElement>(
            '.lp-comment-thread .lp-comment-reply-form form',
        );
        const field = form?.querySelector('textarea');
        if (!form || !field) throw new Error('reply form not found');
        field.value = text;
        form.requestSubmit();
    }, body);
}

async function repeatedIds(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const seen = new Map<string, number>();
        for (const element of document.querySelectorAll('[id]')) {
            seen.set(element.id, (seen.get(element.id) ?? 0) + 1);
        }

        return [...seen].filter(([, count]) => count > 1).map(([id]) => id);
    });
}

const test = base.extend<{ seeded: Seeded }>({
    seeded: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+comsheet+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                { form: { fullName: AUTHOR, email, password: PASSWORD } },
            );
            expect(registered.status()).toBe(200);
            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', {
                timeout: coverageScaled(20000),
            });

            const response = await page.request.post('/dev/seed/document', {
                form: { title: 'Comments sheet plan', markdown: MARKDOWN },
            });
            expect(response.status()).toBe(201);
            const body = await response.json();
            await use({
                projectId: body.projectId,
                documentId: body.documentId,
            });
        },
        { auto: true },
    ],
});

test.describe.configure({ timeout: 90000 });

test.describe('the Comments sheet on a tablet', () => {
    test.use({
        storageState: { cookies: [], origins: [] },
        viewport: { width: 820, height: 1180 },
        hasTouch: true,
        isMobile: true,
    });

    test('a reply updates the open sheet row and a reload shows the same count', async ({
        page,
        seeded,
    }) => {
        await page.goto(reviewPath(seeded));
        await expect(page.locator(DOC)).toBeVisible();
        await postComment(page);

        const bar = page.locator('.lp-review-bar');
        await expect(
            bar.getByRole('button', { name: 'Comments, 1 open' }),
        ).toBeVisible();
        await expect(bar.locator('#review-bar-comments-count')).toHaveText('1');

        await bar.getByRole('button', { name: /^Comments/ }).click();
        const dialog = page.locator('dialog.lp-sheet[open]');
        await expect(dialog).toBeVisible();
        const row = dialog.locator(SHEET_ROW);
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(COMMENT_BODY);
        await expect(row.locator('.lp-comment-row__replies')).toHaveCount(0);

        await replyBehindTheSheet(
            page,
            'A reply that arrives behind the sheet.',
        );
        await expect(row.locator('.lp-comment-row__replies')).toHaveText(
            '1 reply',
            { timeout: coverageScaled(10000) },
        );
        await expect(dialog).toBeVisible();
        expect(await repeatedIds(page)).toEqual([]);

        await page.reload();
        await expect(page.locator(DOC)).toBeVisible();
        await page
            .locator('.lp-review-bar')
            .getByRole('button', { name: /^Comments/ })
            .click();
        await expect(
            page
                .locator('dialog.lp-sheet[open]')
                .locator(`${SHEET_ROW} .lp-comment-row__replies`),
        ).toHaveText('1 reply');
        expect(await repeatedIds(page)).toEqual([]);
    });
});

test.describe('the Comments sheet on a phone', () => {
    test.use({
        storageState: { cookies: [], origins: [] },
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        isMobile: true,
    });

    test('a row shows the author, the age and the replies, and a tap flashes the passage', async ({
        page,
        seeded,
    }) => {
        await page.goto(reviewPath(seeded));
        await expect(page.locator(DOC)).toBeVisible();
        await postComment(page);
        await replyBehindTheSheet(page, 'One reply for the count.');
        await expect(page.locator('.lp-comment--reply')).toHaveCount(1, {
            timeout: coverageScaled(10000),
        });
        await page.reload();
        await expect(page.locator(DOC)).toBeVisible();

        const overflow = await page
            .locator('.lp-review-bar')
            .evaluate((bar) => bar.scrollWidth - bar.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);

        await page
            .locator('.lp-review-bar')
            .getByRole('button', { name: /^Comments/ })
            .click();
        const dialog = page.locator('dialog.lp-sheet[open]');
        const row = dialog.locator(SHEET_ROW);
        await expect(row.locator('.lp-comment-row__author')).toHaveText(AUTHOR);
        await expect(row.locator('.lp-comment-row__age')).toHaveText(/\S/);
        await expect(row.locator('.lp-comment-row__replies')).toHaveText(
            '1 reply',
        );

        await dialog.getByRole('tab', { name: 'Resolved' }).tap();
        await expect(row).toBeHidden();
        await dialog.getByRole('tab', { name: 'Open' }).tap();
        await expect(row).toBeVisible();

        await row.tap();
        await expect(page.locator('dialog.lp-sheet[open]')).toHaveCount(0);

        const inView = await page.evaluate((phrase: string) => {
            const walker = document.createTreeWalker(
                document.querySelector('[data-comment-anchor-target="doc"]')!,
                NodeFilter.SHOW_TEXT,
            );
            let node = walker.nextNode() as Text | null;
            while (node !== null) {
                const index = node.textContent?.indexOf(phrase) ?? -1;
                if (index !== -1) {
                    const range = document.createRange();
                    range.setStart(node, index);
                    range.setEnd(node, index + phrase.length);
                    const box = range.getBoundingClientRect();

                    return box.top >= 0 && box.bottom <= window.innerHeight;
                }
                node = walker.nextNode() as Text | null;
            }

            return false;
        }, PHRASE);
        expect(inView).toBe(true);

        const flashSize = () =>
            page.evaluate(() => CSS.highlights.get('lp-anchor-flash')?.size);
        await expect.poll(flashSize, { timeout: coverageScaled(3000) }).toBe(1);
        await expect.poll(flashSize, { timeout: coverageScaled(4000) }).toBe(0);
    });
});
