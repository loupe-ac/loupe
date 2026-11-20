/**
 * Measures what a Turbo morph refresh breaks. The layout carries the
 * turbo-refresh-method morph and turbo-refresh-scroll preserve metas, so a form
 * that redirects to the same URL, or answers 422, morphs the page. Each test
 * asserts that the state survives or that the page stays usable, so a failure
 * means the morph breaks that scenario.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    agentAccessToken,
    seedDocument,
    signedInTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';
import {
    expectMorphed,
    expectPageInteractive,
    recordRenders,
    resetRenders,
    settled,
} from './morph';

const ROUND_TRIP = { timeout: coverageScaled(15_000) };

const test = base.extend<{ projectId: string }>({
    projectId: [
        async ({ page }, use) => {
            await suppressToolbar(page);
            await suppressWidget(page);
            await recordRenders(page);
            const { projectId } = await seedDocument(page, {
                title: 'E2E Morph Document',
                markdown: '# Morph\n\nA paragraph.',
            });
            await use(projectId);
        },
        { auto: true },
    ],
});

test.use({ viewport: { width: 1440, height: 900 } });
test.describe.configure({ timeout: coverageScaled(60_000) });

async function openColumnSettings(
    page: Page,
    projectId: string,
): Promise<Locator> {
    await page.goto(`/projects/${projectId}/settings/columns`);
    const settings = page.locator('[data-board-column-settings]');
    await expect(settings).toBeVisible();

    return settings;
}

/** A 422 answer to the next POST of the page. */
function refusedPost(page: Page) {
    return page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && response.status() === 422,
    );
}

test('S1 add-column dialog leaves page interactive', async ({
    page,
    projectId,
}) => {
    const settings = await openColumnSettings(page, projectId);
    await settings
        .getByRole('button', { name: 'Add a column', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await settled(dialog);
    await dialog.getByRole('textbox').fill('Parked');
    await resetRenders(page);
    await dialog
        .getByRole('button', { name: 'Add column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: 'Parked', exact: true }),
    ).toBeVisible(ROUND_TRIP);
    await expectMorphed(page);

    const parked = settings.locator('[data-column-id]').filter({
        has: page.getByRole('heading', { name: 'Parked', exact: true }),
    });
    await expectPageInteractive(
        page,
        parked.getByRole('button', { name: 'Move up', exact: true }),
    );
    await expect(settings.locator('.lp-settings-column__name code')).toHaveText(
        ['next', 'in-progress', 'parked', 'done'],
        ROUND_TRIP,
    );
});

test('S2 configure dialog 422 keeps the dialog open and the page interactive', async ({
    page,
    projectId,
}) => {
    const settings = await openColumnSettings(page, projectId);
    await settings
        .getByRole('button', { name: 'Configure Next', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: 'Configure Next',
        exact: true,
    });
    await settled(dialog);
    await dialog.getByLabel('Column name', { exact: true }).fill('Done');
    await resetRenders(page);
    const refused = refusedPost(page);
    await dialog
        .getByRole('button', { name: 'Save column', exact: true })
        .click();
    await refused;
    const error = page
        .locator('[data-field-errors="label"]')
        .filter({ hasText: 'already has this slug' });
    await expect(error).toHaveCount(1, ROUND_TRIP);
    await expectMorphed(page);

    expect
        .soft(
            await page.evaluate(() => {
                const modal = document.querySelector(':modal');

                return (
                    modal instanceof HTMLDialogElement &&
                    modal.open &&
                    (modal.textContent ?? '').includes('already has this slug')
                );
            }),
            'the dialog with the field error is open and is the modal element',
        )
        .toBe(true);
    await expect.soft(error, 'the field error is visible').toBeVisible();

    await page.keyboard.press('Escape');
    await expectPageInteractive(
        page,
        settings.getByRole('button', { name: 'Add a column', exact: true }),
    );
});

const PHRASE_A = 'first anchored phrase';
const PHRASE_B = 'second anchored phrase';
const FILLER = Array.from(
    { length: 12 },
    (_, i) =>
        `Filler paragraph ${i + 1}. ` +
        'This sentence makes the document long enough to scroll. '.repeat(6),
).join('\n\n');
const REVIEW_MARKDOWN = `# Morph review\n\n${FILLER}\n\nThis paragraph holds a ${PHRASE_A} and a ${PHRASE_B}.\n\n${FILLER}`;

const DOC = '[data-comment-anchor-target="doc"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const MARKER = '.lp-comment-markers .lp-comment-marker';

async function openReview(page: Page): Promise<void> {
    const { projectId, documentId } = await seedDocument(page, {
        title: 'E2E Morph Review',
        markdown: REVIEW_MARKDOWN,
    });
    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);
    await expect(page.locator(DOC)).toBeVisible();
}

/** Selects a phrase, chooses Comment and fills the composer, with no post. */
async function writeCommentOn(
    page: Page,
    phrase: string,
    body: string,
): Promise<void> {
    // comment-anchor is a lazy controller, so a selection before it connects shows no toolbar.
    await expect(async () => {
        await selectPhrase(page, phrase);
        await expect(page.locator(TOOLBAR)).toBeVisible({ timeout: 1_000 });
    }).toPass(ROUND_TRIP);
    await page
        .locator(TOOLBAR)
        .getByRole('button', { name: 'Comment', exact: true })
        .click();
    await expect(page.locator(COMPOSER)).toBeVisible();
    await page.locator(COMPOSER_BODY).fill(body);
}

/** Selects a phrase in the prose the way a drag would. */
async function selectPhrase(page: Page, phrase: string): Promise<void> {
    await page.evaluate((wanted: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (docEl === null) {
            throw new Error('doc target not found');
        }
        const walker = document.createTreeWalker(docEl, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(wanted) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + wanted.length);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                docEl.dispatchEvent(
                    new MouseEvent('mouseup', { bubbles: true }),
                );

                return;
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error(`phrase "${wanted}" not found in the prose`);
    }, phrase);
}

async function commentOn(
    page: Page,
    phrase: string,
    body: string,
): Promise<void> {
    await writeCommentOn(page, phrase, body);
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeHidden(ROUND_TRIP);
}

/**
 * Reads the scroll offset of the element that scrolls the document text, the
 * window when no ancestor scrolls. With a fraction, it first scrolls there.
 */
async function setDocScroll(
    page: Page,
    fraction: number | null,
): Promise<number> {
    return page.locator(DOC).evaluate((doc, wanted) => {
        let scroller: Element | null = doc.parentElement;
        while (scroller !== null) {
            const style = getComputedStyle(scroller);
            if (
                /(auto|scroll)/.test(style.overflowY) &&
                scroller.scrollHeight > scroller.clientHeight
            ) {
                break;
            }
            scroller = scroller.parentElement;
        }
        const target = scroller ?? document.scrollingElement!;
        if (wanted !== null) {
            target.scrollTop =
                (target.scrollHeight - target.clientHeight) * wanted;
        }

        return target.scrollTop;
    }, fraction);
}

/** Reads where a phrase of the document text sits in the viewport. */
async function phraseTop(page: Page, phrase: string): Promise<number> {
    return page.locator(DOC).evaluate((doc, wanted) => {
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(wanted) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + wanted.length);

                return range.getBoundingClientRect().top;
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error(`phrase "${wanted}" not found in the prose`);
    }, phrase);
}

function commentsButton(page: Page): Locator {
    return page.locator(
        '.lp-review-toolbar [aria-controls="review-panel-comments"]',
    );
}

async function openFinishReview(page: Page): Promise<Locator> {
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Finish review' });
    await expect(dialog).toBeVisible();
    await settled(dialog);

    return dialog;
}

test('S3 finish-review verdict keeps panels, markers, scroll and interactivity', async ({
    page,
}) => {
    await openReview(page);
    await commentOn(page, PHRASE_A, 'First morph comment.');
    await commentOn(page, PHRASE_B, 'Second morph comment.');
    await expect(commentsButton(page)).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator(MARKER).first()).toBeAttached(ROUND_TRIP);
    const markers = await page.locator(MARKER).count();

    await setDocScroll(page, 0.5);
    const scrollBefore = await setDocScroll(page, null);
    expect(scrollBefore, 'the page scrolled before the submit').toBeGreaterThan(
        100,
    );
    const phraseBefore = await phraseTop(page, PHRASE_A);

    const finish = await openFinishReview(page);
    await finish
        .getByRole('radio', { name: 'Request changes', exact: true })
        .check();
    await finish
        .getByRole('textbox', { name: 'Review note' })
        .fill('Rework it.');
    await finish.getByRole('button', { name: 'Submit review' }).click();
    await expect(
        page.locator('.lp-verdict-chip--changes-requested'),
    ).toBeVisible(ROUND_TRIP);

    await expect
        .soft(commentsButton(page), 'the Comments panel stays open')
        .toHaveAttribute('aria-pressed', 'true');
    await expect
        .soft(page.locator(MARKER), 'the comment markers stay in the gutter')
        .toHaveCount(markers);
    // The flash lands above the text, so the scroll offset grows while the text stays put.
    expect
        .soft(
            Math.abs((await phraseTop(page, PHRASE_A)) - phraseBefore),
            'the text stays within 50 px of where the reader left it',
        )
        .toBeLessThanOrEqual(50);
    await expectPageInteractive(page, commentsButton(page));
});

test('S5 card delete from the drawer keeps the board in list mode', async ({
    page,
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Morph list card');
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(
        page.getByRole('heading', { name: 'Morph list card' }),
    ).toBeVisible(ROUND_TRIP);

    await page.goto(`/projects/${projectId}/board`);
    const listButton = page.getByRole('button', { name: 'List', exact: true });
    // board-view is a lazy controller, so a click before it connects does nothing.
    await expect(async () => {
        await listButton.click();
        await expect(listButton).toHaveAttribute('aria-pressed', 'true', {
            timeout: 1_000,
        });
    }).toPass(ROUND_TRIP);
    const row = page
        .locator('.lp-board-list__row')
        .filter({ hasText: 'Morph list card' });
    await expect(row).toBeVisible(ROUND_TRIP);
    await row.click();
    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    await expect(drawer).toHaveJSProperty('open', true);
    await drawer
        .getByRole('button', { name: 'Delete card', exact: true })
        .click();
    const confirm = drawer.getByRole('button', {
        name: 'Delete it',
        exact: true,
    });
    await expect(confirm).toBeVisible();
    await confirm.click();
    await expect(
        page.locator('.lp-board-card[data-card-title="Morph list card"]'),
    ).toHaveCount(0, ROUND_TRIP);
    await expect(drawer).toHaveJSProperty('open', false, ROUND_TRIP);

    await expect
        .soft(row, 'the list drops the row of the deleted card')
        .toHaveCount(0);
    await expect
        .soft(listButton, 'the List button stays pressed')
        .toHaveAttribute('aria-pressed', 'true');
    await expect
        .soft(page.locator('.lp-board-list'), 'the list view stays visible')
        .toBeVisible();
    await expect
        .soft(
            page.locator('.lp-board__columns'),
            'the board columns stay hidden',
        )
        .toBeHidden();
    await expectPageInteractive(
        page,
        page.getByRole('button', { name: 'Board', exact: true }),
    );
});

test('S6 a switch in one form keeps the text typed in another', async ({
    page,
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/connect`);
    const widget = page.locator('#site-review-widget');
    const origins = page
        .getByTestId('allowed-origins-form')
        .locator('textarea');
    await origins.fill('https://example.test');
    await resetRenders(page);
    await widget.getByRole('button', { name: 'Turn on', exact: true }).click();
    await expect(
        widget.getByRole('button', { name: 'Turn off', exact: true }),
    ).toBeVisible(ROUND_TRIP);
    await expectMorphed(page);

    await expect(origins, 'the typed origins survive').toHaveValue(
        'https://example.test',
    );
});

async function openAgentsWithBridge(
    page: Page,
    projectId: string,
): Promise<{ card: Locator }> {
    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const heartbeat = await page.request.put(
        `/api/v1/bridge/bridges/${bridgeId}/heartbeat`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                projects: [projectId],
                cliVersion: '9.9.9',
                name: 'morph-bridge',
                capabilities: ['commands'],
                workerPools: [
                    { name: 'default', size: 2, inUse: 0, queued: 0 },
                ],
            },
        },
    );
    expect(heartbeat.status()).toBe(200);

    await page.goto(`/projects/${projectId}/agents`);
    const card = page.locator(`[data-agent-connection-id="${bridgeId}"]`);

    return { card };
}

test('S7 pausing a bridge keeps the pools disclosure open', async ({
    page,
    projectId,
}) => {
    const { card } = await openAgentsWithBridge(page, projectId);
    const pools = card.locator('[data-agent-worker-pools]');
    await pools.locator('summary').click();
    await expect(pools).toHaveAttribute('open', '');

    await card.getByRole('button', { name: 'Bridge actions' }).click();
    await resetRenders(page);
    await card.getByRole('button', { name: 'Pause new work' }).click();
    await expect(card.locator('[data-agent-paused]')).toBeVisible(ROUND_TRIP);
    await expectMorphed(page);

    await expect(pools, 'the pools disclosure stays open').toHaveAttribute(
        'open',
        '',
    );
});

test('S13 pausing a bridge on a short window keeps its card in view', async ({
    page,
    projectId,
}) => {
    await page.setViewportSize({ width: 1440, height: 420 });
    const { card } = await openAgentsWithBridge(page, projectId);
    const actions = card.getByRole('button', { name: 'Bridge actions' });
    await actions.scrollIntoViewIfNeeded();
    await actions.click();
    await resetRenders(page);
    await card.getByRole('button', { name: 'Pause new work' }).click();
    await expect(card.locator('[data-agent-paused]')).toBeVisible(ROUND_TRIP);
    await expectMorphed(page);

    await expect(card, 'the agent card is in view').toBeInViewport();
});

async function openApiKeys(page: Page): Promise<Locator> {
    await page.goto('/account/api-keys');
    await page.getByRole('button', { name: 'Create key' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Create an API key' });
    await expect(dialog).toBeVisible();
    await settled(dialog);

    return dialog;
}

test('S8 API key create shows the once-shown dialog and leaves the page interactive', async ({
    page,
}) => {
    const dialog = await openApiKeys(page);
    await dialog.getByLabel('Name', { exact: true }).fill('Morph key');
    await resetRenders(page);
    await dialog.getByRole('button', { name: 'Create key' }).click();
    const once = page.getByRole('dialog', { name: 'Copy your new key' });
    await expect(once).toBeVisible(ROUND_TRIP);
    await expectMorphed(page);
    // The create dialog stays modal until its close animation ends.
    await expect(dialog).toBeHidden();

    expect
        .soft(
            await page.evaluate(() => {
                const modal = document.querySelector(':modal');

                return (
                    modal !== null &&
                    modal.closest('[data-api-key-once]') !== null
                );
            }),
            'the once-shown dialog is the modal element',
        )
        .toBe(true);
    await settled(once);
    await once.getByRole('button', { name: 'I copied the key' }).click();
    await expect(once).toBeHidden();
    await expectPageInteractive(
        page,
        page.getByRole('button', { name: 'Create key' }).first(),
    );
});

test('S9 API key create 422 keeps the dialog open and the page interactive', async ({
    page,
}) => {
    const dialog = await openApiKeys(page);
    await dialog.locator('form').evaluate((form) => {
        (form as HTMLFormElement).noValidate = true;
    });
    await dialog.getByLabel('Name', { exact: true }).fill('');
    await resetRenders(page);
    const refused = refusedPost(page);
    await dialog.getByRole('button', { name: 'Create key' }).click();
    await refused;
    await expect(
        page
            .locator(
                '[aria-labelledby="create-api-key-title"] .lp-field-errors',
            )
            .filter({ hasText: /\S/ }),
    ).toHaveCount(1, ROUND_TRIP);
    await expectMorphed(page);

    expect
        .soft(
            await page.evaluate(() => {
                const modal = document.querySelector(':modal');

                return (
                    modal instanceof HTMLDialogElement &&
                    modal.open &&
                    modal.getAttribute('aria-labelledby') ===
                        'create-api-key-title'
                );
            }),
            'the create dialog is open and is the modal element',
        )
        .toBe(true);
    await page.keyboard.press('Escape');
    await expectPageInteractive(
        page,
        page.getByRole('button', { name: 'Create key' }).first(),
    );
});

test('S10 the save flash shows in the viewport after a scrolled save', async ({
    page,
    projectId,
}) => {
    await page.setViewportSize({ width: 1440, height: 260 });
    await page.goto(`/projects/${projectId}/settings/automation`);
    const settings = page.locator('[data-board-automation-settings]');
    const save = settings.getByRole('button', { name: 'Save automation' });
    await save.scrollIntoViewIfNeeded();
    await expect(save).toBeInViewport();
    const scrolled = await save.evaluate((button) => {
        let offset = window.scrollY;
        for (let node = button.parentElement; node; node = node.parentElement) {
            offset += node.scrollTop;
        }

        return offset;
    });
    expect(scrolled, 'the page scrolled before the save').toBeGreaterThan(0);
    await resetRenders(page);
    await save.click();
    const flash = page.getByText('Automation settings saved.');
    await expect(flash).toBeAttached(ROUND_TRIP);
    await expectMorphed(page);

    await expect(
        flash,
        'the success flash is in the viewport',
    ).toBeInViewport();
});

test('S12 a verdict keeps an unposted comment and leaves the page interactive', async ({
    page,
}) => {
    await openReview(page);
    await writeCommentOn(page, PHRASE_A, 'Typed and not posted.');
    const finish = await openFinishReview(page);
    await expect(
        page.locator(COMPOSER_BODY),
        'the composer still holds the text with the dialog open',
    ).toHaveValue('Typed and not posted.');
    // Request changes keeps the document open to comments, so the server still renders the composer.
    await finish
        .getByRole('radio', { name: 'Request changes', exact: true })
        .check();
    await finish
        .getByRole('textbox', { name: 'Review note' })
        .fill('Rework it.');
    await finish.getByRole('button', { name: 'Submit review' }).click();
    await expect(
        page.locator('.lp-verdict-chip--changes-requested'),
    ).toBeVisible(ROUND_TRIP);

    await expect
        .soft(page.locator(COMPOSER_BODY), 'the unposted comment survives')
        .toHaveValue('Typed and not posted.');
    await expectPageInteractive(page, commentsButton(page));
});
