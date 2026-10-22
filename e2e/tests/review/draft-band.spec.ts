/**
 * The band of a draft document, the confirmation of Publish now, and the
 * warning and second confirmation of Finish review. Scenarios S1 to S8, S11
 * and S12 of the product design. The live scenarios S9 and S10 are in
 * draft-band-live.spec.ts, because they need the hub.
 */

import { expect, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';
import { seedDraftDesign } from './draft-design';

const test = createTest({
    email: `e2e-draft-band-${Date.now()}@example.com`,
    password: 'E2eDraftBand1!',
});

const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

const band = (page: Page) => page.locator('#draft-band');

async function openFinishReview(page: Page) {
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Finish review' });
    await expect(dialog).toBeVisible();

    return dialog;
}

test('S1: the agent form names the round, and the byline has no publish button', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { failedRounds: 1 });

    await page.goto(draft.reviewUrl);

    await expect(band(page)).toContainText(
        'An agent reviews this draft, round 2 of 6.',
    );
    await expect(band(page)).toContainText(
        'Loupe sends it to your review when the agent review ends.',
    );
    await expect(
        band(page).getByRole('button', { name: 'Publish now' }),
    ).toBeVisible();
    await expect(
        page.locator('.lp-review-doc__byline [data-document-publish]'),
    ).toHaveCount(0);
});

test('S2: the paused form gives the reason and links to the card', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { paused: true });

    await page.goto(draft.reviewUrl);

    await expect(band(page)).toContainText(
        'The agent review of this draft is paused.',
    );
    await expect(band(page)).toContainText(
        'Loupe does not publish the draft until the review goes on.',
    );
    await expect(band(page).locator('[data-draft-band-card]')).toHaveAttribute(
        'href',
        `/projects/${draft.projectId}/board/cards/${draft.cardId}`,
    );
    await expect(
        band(page).getByRole('button', { name: 'Publish now' }),
    ).toBeVisible();
});

test('S3 and S5: a plain draft says it is not in review, and Publish needs no dialog', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { linkCard: false });

    await page.goto(draft.reviewUrl);

    await expect(band(page)).toContainText(
        'This document is not in review yet. Publish sends it to review.',
    );
    await band(page)
        .getByRole('button', { name: 'Publish', exact: true })
        .click();

    await expect(page.locator('#draft-band')).toHaveCount(0, ROUND_TRIP);
    await expect(page.locator('#draft-review-warning')).toHaveCount(0);
});

test('S4: Publish now asks first, Cancel keeps the draft, and the confirm publishes', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { failedRounds: 1 });
    await page.goto(draft.reviewUrl);

    await band(page).getByRole('button', { name: 'Publish now' }).click();
    const dialog = page.getByRole('dialog', { name: 'Publish now?' });
    await expect(dialog).toContainText(
        'An agent still reviews this draft, round 2 of 6.',
    );
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(band(page)).toBeVisible();

    await band(page).getByRole('button', { name: 'Publish now' }).click();
    await dialog.getByRole('button', { name: 'Publish now' }).click();

    await expect(page.locator('#draft-band')).toHaveCount(0, ROUND_TRIP);
    await expect(page.locator('#draft-review-warning')).toHaveCount(0);
});

test('S6: Finish review warns, asks again, and Go back keeps the verdict and the note', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { failedRounds: 1 });
    await page.goto(draft.reviewUrl);

    const finish = await openFinishReview(page);
    await expect(finish.locator('#draft-review-warning')).toContainText(
        'An agent still reviews this draft, round 2 of 6.',
    );
    await finish.getByRole('radio', { name: 'Approve', exact: true }).check();
    await finish
        .getByRole('textbox', { name: 'Review note' })
        .fill('Looks right.');
    await finish.getByRole('button', { name: 'Submit review' }).click();

    const confirm = page.getByRole('dialog', {
        name: 'Stop the agent review?',
    });
    await expect(confirm).toBeVisible();
    await expect(
        confirm.getByRole('button', { name: 'Stop the review and approve' }),
    ).toBeVisible();
    await confirm.getByRole('button', { name: 'Go back' }).click();

    await expect(confirm).toBeHidden();
    await expect(finish).toBeVisible();
    await expect(
        finish.getByRole('radio', { name: 'Approve', exact: true }),
    ).toBeChecked();
    await expect(
        finish.getByRole('textbox', { name: 'Review note' }),
    ).toHaveValue('Looks right.');

    await finish.getByRole('button', { name: 'Submit review' }).click();
    await confirm
        .getByRole('button', { name: 'Stop the review and approve' })
        .click();
    await expect(page.locator('.lp-verdict-chip--approved')).toBeVisible(
        ROUND_TRIP,
    );
});

test('S7: Request changes gets its own confirm button', async ({ page }) => {
    test.slow();
    const draft = await seedDraftDesign(page, { failedRounds: 1 });
    await page.goto(draft.reviewUrl);

    const finish = await openFinishReview(page);
    await finish
        .getByRole('radio', { name: 'Request changes', exact: true })
        .check();
    await finish
        .getByRole('textbox', { name: 'Review note' })
        .fill('Rework the rollout.');
    await finish.getByRole('button', { name: 'Submit review' }).click();

    const confirm = page.getByRole('dialog', {
        name: 'Stop the agent review?',
    });
    await confirm
        .getByRole('button', { name: 'Stop the review and request changes' })
        .click();

    await expect(page.locator('.lp-verdict-chip')).toHaveCount(1, ROUND_TRIP);
    await expect(page.locator('.lp-verdict-chip--approved')).toHaveCount(0);
});

test('S8: a plain draft gets one line in Finish review and submits in one step', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { linkCard: false });
    await page.goto(draft.reviewUrl);

    const finish = await openFinishReview(page);
    await expect(finish.locator('#draft-review-warning')).toHaveText(
        'This document is still a draft.',
    );
    await finish.getByRole('radio', { name: 'Approve', exact: true }).check();
    await finish.getByRole('button', { name: 'Submit review' }).click();

    await expect(page.locator('.lp-verdict-chip--approved')).toBeVisible(
        ROUND_TRIP,
    );
    await expect(
        page.getByRole('dialog', { name: 'Stop the agent review?' }),
    ).toHaveCount(0);
});

test('S11: a document in review shows no band', async ({ page }) => {
    test.slow();
    const draft = await seedDraftDesign(page, { linkCard: false });
    await page.goto(draft.reviewUrl);
    await band(page)
        .getByRole('button', { name: 'Publish', exact: true })
        .click();
    await expect(page.locator('#draft-band')).toHaveCount(0, ROUND_TRIP);

    await page.goto(draft.reviewUrl);

    await expect(page.locator('#draft-band')).toHaveCount(0);
    await expect(page.locator('[data-draft-band]')).toHaveCount(0);
    await expect(page.locator('#draft-review-warning')).toHaveCount(0);
});

test('S12: the paused confirmations say paused and never that an agent still reviews', async ({
    page,
}) => {
    test.slow();
    const draft = await seedDraftDesign(page, { paused: true });
    await page.goto(draft.reviewUrl);

    await band(page).getByRole('button', { name: 'Publish now' }).click();
    const publish = page.getByRole('dialog', { name: 'Publish now?' });
    await expect(publish).toContainText(
        'The agent review of this draft is paused, at round 1 of 6.',
    );
    await expect(publish).not.toContainText('still reviews');
    await publish.getByRole('button', { name: 'Cancel' }).click();
    await expect(publish).toBeHidden();

    const finish = await openFinishReview(page);
    const warning = finish.locator('#draft-review-warning');
    await expect(warning).toContainText(
        'The agent review of this draft is paused, at round 1 of 6.',
    );
    await expect(warning).not.toContainText('still reviews');
    await finish.getByRole('radio', { name: 'Approve', exact: true }).check();
    await finish.getByRole('button', { name: 'Submit review' }).click();

    const confirm = page.getByRole('dialog', {
        name: 'Stop the agent review?',
    });
    await expect(confirm).toContainText('while its agent review is paused');
    await expect(confirm).not.toContainText('still reviews');
});
