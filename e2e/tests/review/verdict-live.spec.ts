/**
 * A verdict given in one tab reaches a second tab of an in-review document
 * without a reload, and the comment that the second tab holds in progress
 * keeps its text. The hub delivers the signal, so the run needs a Mercure hub
 * the browser can reach.
 */

import { test, expect, type Page } from '@playwright/test';
import { signedInPage, suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eVerdictLive1!';
const PHRASE = 'a phrase for the comment';
const DRAFTED = 'A thought that is still in progress.';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';

const MARKDOWN = `# Verdict live

Some prose with ${PHRASE} inside it.

More prose after it.`;

async function selectPhrase(page: Page): Promise<void> {
    await page.evaluate((phrase) => {
        const doc = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!doc) throw new Error('No document pane');
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(phrase) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + phrase.length);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                doc.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));

                return;
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error(`Phrase "${phrase}" not found`);
    }, PHRASE);
}

async function open(page: Page, reviewUrl: string): Promise<void> {
    await suppressToolbar(page);
    await suppressWidget(page);
    await page.goto(reviewUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(
        page.locator('[data-review-draft-live-connected]'),
    ).toHaveCount(1, { timeout: coverageScaled(15000) });
}

test('a verdict in one tab shows in a second tab and keeps its comment in progress', async ({
    browser,
    request,
}) => {
    test.slow();
    const flag = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'live_updates.enabled', enabled: 1 },
    });
    expect(flag.ok()).toBeTruthy();

    const email = `e2e+verdictlive+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Verdict Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const author = await signedInPage(browser, email, PASSWORD);
    const seeded = await author.request.post('/dev/seed/document', {
        form: { title: 'Verdict live', markdown: MARKDOWN },
    });
    expect(seeded.status()).toBe(201);
    const body = (await seeded.json()) as {
        documentId: string;
        projectId: string;
    };
    const reviewUrl = `/projects/${body.projectId}/documents/${body.documentId}/review`;

    const watcher = await author.context().newPage();
    await open(author, reviewUrl);
    await open(watcher, reviewUrl);
    await expect(watcher.locator('.lp-verdict-chip')).toHaveCount(0);

    await selectPhrase(watcher);
    await expect(watcher.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await watcher.getByRole('button', { name: 'Comment', exact: true }).click();
    await watcher.locator(COMPOSER_BODY).fill(DRAFTED);
    // A field that has lost focus is the one a morph would reset.
    await watcher.locator(COMPOSER_BODY).evaluate((field) => field.blur());

    await author
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const finish = author.getByRole('dialog', { name: 'Finish review' });
    await finish.getByRole('radio', { name: 'Approve', exact: true }).check();
    await finish.getByRole('button', { name: 'Submit review' }).click();

    await expect(watcher.locator('.lp-verdict-chip--approved')).toBeVisible({
        timeout: coverageScaled(20000),
    });
    await expect(watcher.locator(COMPOSER)).toBeVisible();
    await expect(watcher.locator(COMPOSER_BODY)).toHaveValue(DRAFTED);
});
