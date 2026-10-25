/**
 * The review bar at a phone and a tablet viewport with a touch pointer.
 *
 * Below lg the bar sits at the foot of the review page. Contents, Decisions and
 * More each open a sheet, and Finish review opens the sheet of the review block.
 * Every test drives its own user and documents through the dev-only endpoints,
 * so nothing here touches Mailpit. The reference comes from the MCP
 * document_set_references tool, because the app has no form for it.
 */

import { expect, type Page } from '@playwright/test';
import {
    accessToken,
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eReviewBar1!';

const VIEWPORTS = [
    { name: 'phone', viewport: { width: 390, height: 844 } },
    { name: 'tablet', viewport: { width: 820, height: 1180 } },
];

const BAR = '.lp-review-bar';
const DECISION_ID = 'rollout-order';
const LAST_LINE = 'The very last paragraph of the document.';

const FILLER = '\nA paragraph that fills the screen.\n'.repeat(40);
const MARKDOWN = `# Plan

## Scope

The first section.
${FILLER}
## Risks

The second section.

<!-- decision: ${DECISION_ID} -->

1. Ship the migration first
2. Ship the reader first

<!-- /decision -->
${FILLER}
## Rollout

${LAST_LINE}
`;
const REVISION = MARKDOWN.replace('The first section.', 'The revised section.');
const PLAIN_MARKDOWN = 'A document with no headings at all.\n';

interface Seeded {
    projectId: string;
    documentId: string;
}

async function seedDocument(
    page: Page,
    title: string,
    markdown: string,
    revisions?: string[],
): Promise<Seeded> {
    const form: Record<string, string> = { title, markdown };
    if (revisions !== undefined) {
        form.revisions = JSON.stringify(revisions);
    }
    const response = await page.request.post('/dev/seed/document', { form });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return { projectId: body.projectId, documentId: body.documentId };
}

async function setReferences(
    page: Page,
    seeded: Seeded,
    references: string[],
): Promise<void> {
    const token = await accessToken(page, 'mcp', seeded.projectId);
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
    const response = await page.request.post('/mcp', {
        headers,
        data: {
            jsonrpc: '2.0',
            id: 2,
            method: 'tools/call',
            params: {
                name: 'document_set_references',
                arguments: { documentId: seeded.documentId, references },
            },
        },
    });
    const answer = await response.json();
    expect(answer.result?.isError, JSON.stringify(answer)).toBeFalsy();
}

function reviewPath(seeded: Seeded): string {
    return `/projects/${seeded.projectId}/documents/${seeded.documentId}/review`;
}

const test = base.extend<{ seeded: Seeded }>({
    seeded: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+revbar+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                {
                    form: {
                        fullName: 'E2E Review Bar',
                        email,
                        password: PASSWORD,
                    },
                },
            );
            expect(registered.status()).toBe(200);
            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', {
                timeout: coverageScaled(20000),
            });

            await use(
                await seedDocument(page, 'Review bar plan', MARKDOWN, [
                    REVISION,
                ]),
            );
        },
        { auto: true },
    ],
});

for (const { name, viewport } of VIEWPORTS) {
    test.describe(`the review bar on a ${name}`, () => {
        test.use({
            storageState: { cookies: [], origins: [] },
            viewport,
            hasTouch: true,
            isMobile: true,
        });

        const bar = (page: Page) => page.locator(BAR);
        const barButton = (page: Page, label: string | RegExp) =>
            bar(page).getByRole('button', { name: label });

        test('the bar is fixed at the foot with its five controls', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            await expect(bar(page)).toBeVisible();

            const box = await bar(page).boundingBox();
            expect(box!.y + box!.height).toBeCloseTo(viewport.height, 0);
            expect(box!.width).toBeCloseTo(viewport.width, 0);

            await expect(barButton(page, 'Contents')).toBeVisible();
            await expect(barButton(page, 'Comments, 0 open')).toBeVisible();
            await expect(barButton(page, /^Decisions/)).toBeVisible();
            await expect(
                bar(page).locator('#review-bar-decisions-count'),
            ).toHaveText('0/1');
            await expect(barButton(page, 'More')).toBeVisible();
            await expect(barButton(page, 'Finish review')).toBeVisible();

            const sizes = await bar(page)
                .getByRole('button')
                .evaluateAll((elements) =>
                    elements
                        .filter((element) => element.checkVisibility())
                        .map((element) => element.getBoundingClientRect())
                        .map((rect) => Math.min(rect.width, rect.height)),
                );
            expect(sizes.length).toBe(5);
            for (const size of sizes) {
                expect(
                    size,
                    'a bar button is under the touch target',
                ).toBeGreaterThanOrEqual(44);
            }
        });

        test('the bar is gone at 1024px and back below it', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            await expect(bar(page)).toBeVisible();
            await page.setViewportSize({ width: 1024, height: 768 });
            await expect(bar(page)).toBeHidden();
            await page.setViewportSize(viewport);
            await expect(bar(page)).toBeVisible();
        });

        test('a Contents row scrolls to its heading and closes the sheet', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            await barButton(page, 'Contents').tap();
            const sheet = page.getByRole('dialog', {
                name: 'Contents',
                exact: true,
            });
            await expect(sheet).toBeVisible();

            await sheet.getByRole('link', { name: 'Rollout' }).tap();
            await expect(sheet).toBeHidden();
            await expect(
                page.getByRole('heading', { name: 'Rollout' }),
            ).toBeInViewport();
        });

        test('answering a decision updates the Decisions count live', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            const count = bar(page).locator('#review-bar-decisions-count');
            await expect(count).toHaveText('0/1');

            const saved = page.waitForResponse(
                (each) =>
                    each.url().endsWith('/decisions/answer') &&
                    each.request().method() === 'POST',
                { timeout: coverageScaled(15000) },
            );
            await page
                .getByRole('radio', { name: 'Ship the reader first' })
                .check();
            expect((await saved).status()).toBe(200);

            await expect(count).toHaveText('1/1');
            await barButton(page, /^Decisions/).tap();
            const sheet = page.getByRole('dialog', {
                name: 'Decisions',
                exact: true,
            });
            await expect(sheet).toBeVisible();
            await expect(
                sheet.locator('#review-bar-decisions-head-count'),
            ).toHaveText('1/1');
            await expect(sheet.getByRole('link')).toHaveCount(1);
        });

        test('a Decisions row jumps to the decision and closes the sheet', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            await barButton(page, /^Decisions/).tap();
            const sheet = page.getByRole('dialog', {
                name: 'Decisions',
                exact: true,
            });
            await sheet.getByRole('link').first().tap();
            await expect(sheet).toBeHidden();
            await expect(
                page.getByRole('radio', { name: 'Ship the reader first' }),
            ).toBeInViewport();
        });

        test('More drills down to Versions with Diff links and to References', async ({
            page,
            seeded,
        }) => {
            const other = await seedDocument(
                page,
                'Cache plan',
                PLAIN_MARKDOWN,
            );
            await setReferences(page, seeded, [other.documentId]);
            await page.goto(reviewPath(seeded));

            await barButton(page, 'More').tap();
            const sheet = page.getByRole('dialog', {
                name: 'More',
                exact: true,
            });
            await expect(sheet).toBeVisible();
            const back = sheet.getByRole('button', { name: 'Back' });
            await expect(back).toBeHidden();

            await sheet.getByRole('button', { name: /^Versions/ }).tap();
            await expect(back).toBeVisible();
            const diff = sheet.getByRole('link', {
                name: 'What changed since v1',
            });
            await expect(diff).toBeVisible();

            await back.tap();
            await sheet.getByRole('button', { name: /^References/ }).tap();
            await expect(
                sheet.getByRole('link', { name: /Cache plan/ }),
            ).toBeVisible();
            await expect(diff).toBeHidden();

            await back.tap();
            await sheet.getByRole('button', { name: /^Versions/ }).tap();
            await diff.tap();
            await expect(page.locator('.lp-diff-bar')).toBeVisible({
                timeout: coverageScaled(15000),
            });
        });

        test('Finish review opens the verdict sheet and blocks Approve while a decision waits', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            await barButton(page, 'Finish review').tap();
            const sheet = page.getByRole('dialog', { name: 'Finish review' });
            await expect(sheet).toBeVisible();

            const approve = sheet.locator('input[value="approved"]');
            await expect(approve).toBeDisabled();
            await expect(
                sheet.locator('input[value="changes-requested"]'),
            ).toBeEnabled();

            await sheet
                .getByRole('button', { name: 'Cancel', exact: true })
                .tap();
            await expect(sheet).toBeHidden();
        });

        test('Finish review unblocks Approve once the decision is answered', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            const saved = page.waitForResponse(
                (each) =>
                    each.url().endsWith('/decisions/answer') &&
                    each.request().method() === 'POST',
                { timeout: coverageScaled(15000) },
            );
            await page
                .getByRole('radio', {
                    name: 'Ship the migration first',
                    exact: true,
                })
                .check();
            expect((await saved).status()).toBe(200);

            await barButton(page, 'Finish review').tap();
            const sheet = page.getByRole('dialog', { name: 'Finish review' });
            await expect(sheet).toBeVisible();
            const approve = sheet.locator('input[value="approved"]');
            await expect(approve).toBeEnabled();
            await expect(approve).toBeChecked();
        });

        test('the last paragraph scrolls clear of the bar', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            const last = page.getByText(LAST_LINE, { exact: true });
            await last.evaluate((element) =>
                element.scrollIntoView({ block: 'end' }),
            );
            await page.locator('.lp-main').evaluate((element) => {
                element.scrollTop = element.scrollHeight;
            });
            await expect(last).toBeInViewport();
            const lastBox = await last.boundingBox();
            const barBox = await bar(page).boundingBox();
            expect(lastBox!.y + lastBox!.height).toBeLessThanOrEqual(barBox!.y);
        });

        test('a phone draws no Revise action and a tablet does', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            const revise = page.getByRole('button', {
                name: 'Revise',
                exact: true,
            });
            if (viewport.width < 640) {
                await expect(revise).toBeHidden();
            } else {
                await expect(revise).toBeVisible();
            }
        });

        test('a finished review leaves the Comments button and no bar that opens nothing', async ({
            page,
        }) => {
            const plain = await seedDocument(page, 'Plain', PLAIN_MARKDOWN);
            await page.goto(reviewPath(plain));
            await barButton(page, 'Finish review').tap();
            const sheet = page.getByRole('dialog', { name: 'Finish review' });
            await sheet.locator('input[value="approved"]').check();
            await sheet.getByRole('button', { name: 'Submit review' }).tap();
            await expect(page.locator('.lp-verdict-chip')).toBeVisible({
                timeout: coverageScaled(20000),
            });
            await expect(barButton(page, 'Finish review')).toBeHidden();
            await expect(barButton(page, 'Comments, 0 open')).toBeVisible();
            await expect(bar(page).getByRole('button')).toHaveCount(1);
        });

        test('the bar hides while the annotation composer is open', async ({
            page,
            seeded,
        }) => {
            await page.goto(reviewPath(seeded));
            await expect(bar(page)).toBeVisible();
            await page.evaluate(() => {
                const doc = document.querySelector(
                    '[data-comment-anchor-target="doc"]',
                );
                const walker = document.createTreeWalker(
                    doc!,
                    NodeFilter.SHOW_TEXT,
                );
                for (let n = walker.nextNode(); n; n = walker.nextNode()) {
                    const at =
                        n.textContent?.indexOf('The revised section') ?? -1;
                    if (at === -1) continue;
                    const range = document.createRange();
                    range.setStart(n, at);
                    range.setEnd(n, at + 'The revised section'.length);
                    const selection = window.getSelection()!;
                    selection.removeAllRanges();
                    selection.addRange(range);
                    return;
                }
                throw new Error('phrase not found');
            });
            await page
                .getByRole('button', { name: 'Comment', exact: true })
                .tap();
            await expect(
                page.locator('[data-comment-anchor-target="composer"]'),
            ).toBeVisible();
            await expect(bar(page)).toBeHidden();
        });
    });
}
