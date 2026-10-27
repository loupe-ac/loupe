import { expect } from '@playwright/test';
import { hubStubbedTest as test } from '../fixtures';

/**
 * The comparison pages show only where the instance has a site host. The CI
 * target sets SITE_HOST to localhost, so this host serves them.
 */
test.use({ storageState: { cookies: [], origins: [] } });

test('the footer link opens the list of comparisons', async ({ page }) => {
    await page.goto('/privacy');
    await page
        .locator('.lp-home-footer')
        .getByRole('link', { name: 'Compare', exact: true })
        .click();

    await expect(
        page.getByRole('heading', {
            name: 'Compare Loupe with other tools',
            level: 1,
        }),
    ).toBeVisible({ timeout: 15000 });
    await expect(page).toHaveURL('/compare');
});

test('the list opens each comparison page', async ({ page }) => {
    await page.goto('/compare');
    const links = page.locator('.lp-legal__body a[href^="/compare/"]');
    const count = await links.count();
    expect(count).toBeGreaterThan(0);

    const paths: string[] = [];
    for (let index = 0; index < count; index++) {
        paths.push((await links.nth(index).getAttribute('href')) ?? '');
    }

    for (const path of paths) {
        await page.goto('/compare');
        await page.locator(`.lp-legal__body a[href="${path}"]`).click();
        await expect(page).toHaveURL(path, { timeout: 15000 });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.locator('.lp-legal__updated')).toContainText(
            'Compared on',
        );
        await expect(
            page.getByRole('link', { name: 'Self-host Loupe' }),
        ).toBeVisible();
    }
});
