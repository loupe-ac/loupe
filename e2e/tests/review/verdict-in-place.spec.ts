/**
 * A verdict and its undo update the review page in place: the page is not
 * replaced, the finish sheet closes and the top bar shows the new state.
 */

import { expect } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-verdict-in-place-${Date.now()}@example.com`,
    password: 'E2eVerdictInPlace1!',
});

const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

test('a verdict and its undo keep the page and close the sheet', async ({
    page,
}) => {
    test.slow();
    await suppressToolbar(page);
    await suppressWidget(page);
    await page.goto('/projects');
    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: `Verdict in place ${Date.now()}`,
            markdown: '# Verdict in place\n\nSome prose to review.',
        },
    });
    expect(seeded.ok()).toBeTruthy();
    const { documentId, projectId } = await seeded.json();
    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);

    await page.evaluate(() => {
        (window as unknown as { pageMarker: string }).pageMarker = 'kept';
    });

    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Finish review' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('radio', { name: 'Approve', exact: true }).check();
    await dialog.getByRole('button', { name: 'Submit review' }).click();

    await expect(dialog).toBeHidden(ROUND_TRIP);
    await expect(page.locator('.lp-verdict-chip__trigger')).toBeVisible(
        ROUND_TRIP,
    );
    await expect(page.locator('.lp-flash--success')).toBeVisible(ROUND_TRIP);
    expect(
        await page.evaluate(
            () => (window as unknown as { pageMarker?: string }).pageMarker,
        ),
    ).toBe('kept');

    await page.locator('.lp-verdict-chip__trigger').click();
    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Finish review', exact: true }),
    ).toBeVisible(ROUND_TRIP);
    expect(
        await page.evaluate(
            () => (window as unknown as { pageMarker?: string }).pageMarker,
        ),
    ).toBe('kept');

    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await dialog
        .getByRole('radio', { name: 'Request changes', exact: true })
        .check();
    await dialog.getByRole('button', { name: 'Submit review' }).click();
    await expect(dialog).toBeHidden(ROUND_TRIP);
    await expect(
        page.locator('.lp-verdict-chip--changes-requested'),
    ).toBeVisible(ROUND_TRIP);
});
