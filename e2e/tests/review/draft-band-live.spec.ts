/**
 * The band and the Finish review warning follow the draft without a reload:
 * scenarios S9 and S10 of the product design, and the open Finish review
 * dialog that keeps its verdict and note. The hub delivers the signal, so the
 * run needs a Mercure hub the browser can reach, and the spec turns the live
 * updates flag on.
 */

import { test, expect, type Page } from '@playwright/test';
import { signedInPage } from '../fixtures';
import { coverageScaled } from '../timeouts';
import { seedDraftDesign, type DraftDesign } from './draft-design';

const RUN = Date.now();
const PASSWORD = 'E2eDraftBandLive1!';
const LIVE = { timeout: coverageScaled(20_000) };

async function setup(
    browser: Parameters<typeof signedInPage>[0],
    request: Parameters<Parameters<typeof test>[2]>[0]['request'],
    tag: string,
): Promise<{ author: Page; watcher: Page; draft: DraftDesign }> {
    const flag = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'live_updates.enabled', enabled: 1 },
    });
    expect(flag.ok()).toBeTruthy();

    const email = `e2e+draftbandlive+${tag}+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Draft Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const author = await signedInPage(browser, email, PASSWORD);
    const draft = await seedDraftDesign(author, { failedRounds: 1 });

    // Its own browser context: the hub cookie is one per browser, and the author's pages renew it with their own topics.
    const watcher = await signedInPage(browser, email, PASSWORD);
    await watcher.goto(draft.reviewUrl);
    await expect(watcher.locator('#draft-band')).toContainText('round 2 of 6');
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(
        watcher.locator('[data-review-draft-live-connected]'),
    ).toHaveCount(1, LIVE);

    return { author, watcher, draft };
}

test('S10: a new round shows in the band without a reload', async ({
    browser,
    request,
}) => {
    test.slow();
    const { author, watcher, draft } = await setup(browser, request, 'round');

    const seeded = await author.request.post(
        `/dev/seed/document-agent-review/${draft.projectId}/${draft.documentId}`,
    );
    expect(seeded.status()).toBe(201);

    await expect(watcher.locator('#draft-band')).toContainText(
        'round 3 of 6',
        LIVE,
    );
});

test('S9: the band goes away and the page shows In review when the draft is published', async ({
    browser,
    request,
}) => {
    test.slow();
    const { author, watcher, draft } = await setup(browser, request, 'publish');

    await author.goto(draft.reviewUrl);
    await author
        .locator('#draft-band')
        .getByRole('button', { name: 'Publish now' })
        .click();
    await author
        .getByRole('dialog', { name: 'Publish now?' })
        .getByRole('button', { name: 'Publish now' })
        .click();

    await expect(watcher.locator('#draft-band')).toHaveCount(0, LIVE);
    await expect(watcher.locator('#draft-review-warning')).toHaveCount(0, LIVE);
    await expect(
        watcher.locator('[data-controller~="review-draft-live"]'),
    ).toHaveCount(0, LIVE);
});

test('an open Finish review dialog drops its warning live and keeps the verdict and the note', async ({
    browser,
    request,
}) => {
    test.slow();
    const { author, watcher, draft } = await setup(browser, request, 'dialog');

    await watcher
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const finish = watcher.getByRole('dialog', { name: 'Finish review' });
    await finish.getByRole('radio', { name: 'Approve', exact: true }).check();
    await finish
        .getByRole('textbox', { name: 'Review note' })
        .fill('Keep this note.');

    const seeded = await author.request.post(
        `/dev/seed/document-agent-review/${draft.projectId}/${draft.documentId}`,
    );
    expect(seeded.status()).toBe(201);

    await expect(finish.locator('#draft-review-warning')).toContainText(
        'round 3 of 6',
        LIVE,
    );
    await expect(
        finish.getByRole('radio', { name: 'Approve', exact: true }),
    ).toBeChecked();
    await expect(
        finish.getByRole('textbox', { name: 'Review note' }),
    ).toHaveValue('Keep this note.');
});
