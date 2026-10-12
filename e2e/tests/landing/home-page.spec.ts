/**
 * The home page of loupe.ac, shown to a visitor who is not signed in. The dev
 * and test environments show it because `app.home_page_host` is null there.
 * The joins that need a closed registration cap live in
 * `home-page-waitlist.spec.ts`, because the cap is process-wide.
 */

import { expect } from '@playwright/test';
import { hubStubbedTest as test } from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });

test.describe('home page', () => {
    test('shows the headline, the waitlist button and no price', async ({
        page,
    }) => {
        await page.goto('/');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'From ideas to features.',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Join the private beta waitlist' }),
        ).toBeVisible();

        const text = (await page.locator('main').innerText()).toLowerCase();
        for (const word of ['$/month', 'per month', 'free trial', 'pricing']) {
            expect(text).not.toContain(word);
        }
    });

    test('every call to action leads to the waitlist', async ({ page }) => {
        await page.goto('/');

        const buttons = page.getByRole('link', {
            name: /join the (private beta )?waitlist|I'm all set|Missing one/i,
        });
        expect(await buttons.count()).toBeGreaterThanOrEqual(6);
        for (const button of await buttons.all()) {
            await expect(button).toHaveAttribute('href', /\/waitlist$/);
        }
    });

    test('steps through the features and wraps around', async ({ page }) => {
        await page.goto('/');

        const board = page.getByRole('button', { name: /^Board/ });
        const analytics = page.getByRole('button', { name: /^Analytics/ });
        const documentReview = page.getByRole('button', {
            name: /^Document review/,
        });
        await expect(board).toHaveAttribute('aria-pressed', 'true');

        await page.getByRole('button', { name: 'Previous feature' }).click();
        await expect(analytics).toHaveAttribute('aria-pressed', 'true');
        await expect(
            page.getByText('Cost per finished card, merge rate'),
        ).toBeVisible();

        await documentReview.click();
        await expect(documentReview).toHaveAttribute('aria-pressed', 'true');
        await expect(
            page.getByText('Product and tech designs arrive rendered.'),
        ).toBeVisible();
        await expect(
            page.locator('.lp-home-slide[aria-hidden="false"]'),
        ).toContainText('Product design: nightly price on search cards');

        await page.getByRole('button', { name: 'Next feature' }).click();
        await page.getByRole('button', { name: 'Next feature' }).click();
        await page.getByRole('button', { name: 'Next feature' }).click();
        await page.getByRole('button', { name: 'Next feature' }).click();
        await expect(analytics).toHaveAttribute('aria-pressed', 'true');
        await page.getByRole('button', { name: 'Next feature' }).click();
        await expect(board).toHaveAttribute('aria-pressed', 'true');
    });

    test('embeds the Loupe Explainer and sizes the frame to it', async ({
        page,
    }) => {
        await page.goto('/');

        const frame = page.getByTitle(/Loupe Explainer/);
        await frame.scrollIntoViewIfNeeded();
        const explainer = page.frameLocator('iframe.landing-explainer');
        await expect(
            explainer.getByRole('button', { name: /Merge/ }).first(),
        ).toBeVisible();

        await expect
            .poll(async () =>
                frame.evaluate(
                    (element) =>
                        element.clientHeight -
                        element.contentDocument.documentElement.scrollHeight,
                ),
            )
            .toBe(0);
    });

    test('starts with the "Use something else?" block closed', async ({
        page,
    }) => {
        await page.goto('/');

        const toggle = page.getByRole('button', {
            name: /Use something else\?/,
        });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByLabel('Something else')).toBeHidden();

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await page.getByLabel('GitLab').check();
        await page.getByLabel('Cursor').check();
        await expect(page.getByLabel('GitLab')).toBeChecked();
        await expect(page.getByLabel('Something else')).toBeVisible();

        await toggle.click();
        await expect(page.getByLabel('Something else')).toBeHidden();
    });
});

test.describe('home page on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('stacks the sections and never scrolls sideways', async ({ page }) => {
        await page.goto('/');

        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(0);

        const list = await page
            .locator('.lp-home-features__list')
            .boundingBox();
        const stage = await page.locator('.lp-home-stage').boundingBox();
        expect(list).not.toBeNull();
        expect(stage).not.toBeNull();
        expect(list!.y + list!.height).toBeLessThanOrEqual(stage!.y + 1);
    });
});
