/**
 * The document pages follow other actors without a reload. A new version
 * shows the version band, a comment reaches a second tab and the history
 * page, and a new document counts on a later page of the documents list. The
 * hub delivers the signals, so the run needs a Mercure hub the browser can
 * reach.
 */

import { test, expect, type Page } from '@playwright/test';
import { signedInPage, suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';
import { commentRow, showPanel } from './panels';

const RUN = Date.now();
const PASSWORD = 'E2eDocumentLive1!';
const PHRASE = 'a phrase for the comment';
const COMMENT = 'A comment that reaches the second tab.';
const LIVE = { timeout: coverageScaled(20_000) };
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';
const PER_PAGE = 20;

const MARKDOWN = `# Document live

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

async function open(page: Page, url: string, connected: string): Promise<void> {
    await suppressToolbar(page);
    await suppressWidget(page);
    await page.goto(url);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(page.locator(connected)).toHaveCount(1, {
        timeout: coverageScaled(15000),
    });
}

test('a new version, a comment and a later page follow another actor', async ({
    browser,
    request,
}) => {
    test.slow();
    test.setTimeout(coverageScaled(240_000));
    const email = `e2e+documentlive+${RUN}+r${test.info().repeatEachIndex}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Document Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const author = await signedInPage(browser, email, PASSWORD);
    const seeded = await author.request.post('/dev/seed/document', {
        form: { title: 'Document live', markdown: MARKDOWN },
    });
    expect(seeded.status()).toBe(201);
    const body = (await seeded.json()) as {
        documentId: string;
        projectId: string;
    };
    const reviewUrl = `/projects/${body.projectId}/documents/${body.documentId}/review`;

    const watcher = await author.context().newPage();
    await open(author, reviewUrl, '[data-review-draft-live-connected]');
    await open(watcher, reviewUrl, '[data-review-draft-live-connected]');

    // A comment of one tab reaches the other.
    await selectPhrase(author);
    await expect(author.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await author.getByRole('button', { name: 'Comment', exact: true }).click();
    await author.locator(COMPOSER_BODY).fill(COMMENT);
    await author.getByRole('button', { name: 'Post', exact: true }).click();
    await showPanel(watcher, 'Comments');
    await expect(commentRow(watcher, COMMENT)).toBeVisible(LIVE);

    // A new version shows the version band, and the page keeps its version.
    const revised = await author.request.post(
        `/dev/review/${body.documentId}/revise`,
        {
            form: {
                markdown: `${MARKDOWN}\n\nA paragraph of the second version.`,
                description: 'Added a paragraph.',
            },
        },
    );
    expect(revised.status()).toBe(200);
    const band = watcher.locator('[data-live-band="version"]');
    await expect(band).toBeVisible(LIVE);
    await expect(band).toContainText('Version 2 is out.');

    // The history page gains the version in its table and its top bar.
    const history = await author.context().newPage();
    await open(
        history,
        `${reviewUrl}/history`,
        '[data-review-draft-live-connected]',
    );
    await expect(history.locator('.lp-history__row')).toHaveCount(2);
    await author.request.post(`/dev/review/${body.documentId}/revise`, {
        form: {
            markdown: `${MARKDOWN}\n\nA paragraph of the third version.`,
            description: 'Added another paragraph.',
        },
    });
    await expect(history.locator('.lp-history__row')).toHaveCount(3, LIVE);
    await expect(history.locator('#history-topbar-count')).toContainText(
        '3 versions',
    );
    await expect(history.locator('#history-compare option')).toHaveCount(6);

    // A new document counts on a later page of the documents list.
    for (let index = 0; index < PER_PAGE; index++) {
        const extra = await author.request.post('/dev/seed/document', {
            form: { title: `Filler ${index}`, markdown: '# Filler' },
        });
        expect(extra.status()).toBe(201);
    }
    const later = await author.context().newPage();
    await open(
        later,
        `/projects/${body.projectId}/documents?page=2`,
        '#documents-live[data-controller="live-region"]',
    );
    const added = await author.request.post('/dev/seed/document', {
        form: { title: 'Arrives later', markdown: '# Later' },
    });
    expect(added.status()).toBe(201);
    await expect(later.locator('#documents-live-band')).toContainText(
        '1 new document',
        LIVE,
    );
});
