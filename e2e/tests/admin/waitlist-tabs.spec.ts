/**
 * End-to-end coverage of the admin waitlist tabs: clicking a tab swaps the
 * page through Turbo Drive, so the document and its `window` object survive.
 */

import { expect } from '@playwright/test';
import { ADMIN } from '../admin-helpers';
import { createTest } from '../fixtures';

const test = createTest(ADMIN);

test('the waitlist tabs switch through Turbo with no full page load', async ({
    page,
}) => {
    await page.goto('/admin/waitlist');
    const entriesTab = page.locator('[data-waitlist-tab="entries"]');
    const patternsTab = page.locator('[data-waitlist-tab="block-patterns"]');
    await expect(entriesTab).toHaveAttribute('aria-current', 'page');

    // A full page load builds a new window, so this property would vanish.
    await page.evaluate(() => {
        (window as unknown as { __survivor: boolean }).__survivor = true;
    });

    await patternsTab.click();
    await expect(page).toHaveURL(/\/admin\/waitlist\/block-patterns$/);
    await expect(page.locator('[data-waitlist-block-patterns]')).toBeVisible();
    await expect(patternsTab).toHaveAttribute('aria-current', 'page');

    await entriesTab.click();
    await expect(page).toHaveURL(/\/admin\/waitlist$/);
    await expect(page.locator('[data-waitlist-pick-counts]')).toBeVisible();
    await expect(entriesTab).toHaveAttribute('aria-current', 'page');

    expect(
        await page.evaluate(
            () => (window as unknown as { __survivor?: boolean }).__survivor,
        ),
    ).toBe(true);
});
