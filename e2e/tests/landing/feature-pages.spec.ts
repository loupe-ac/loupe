/**
 * The public feature pages, the index, the footer link and the two site menus.
 * The marketing pages show only where the instance has a site host.
 */

import { expect } from '@playwright/test';
import { hubStubbedTest as test } from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });

const SLUGS = [
    'board',
    'workflow-rules',
    'interactive-sessions',
    'document-review',
    'inbox',
    'agent-review',
    'live-review',
    'analytics',
];

test.describe('desktop', () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    test('a feature page shows the hero, three blocks, seven tiles and the closing band', async ({
        page,
    }) => {
        await page.goto('/features/document-review');

        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(
            page.locator('[data-feature-picture="hero"] .lp-shot'),
        ).toBeVisible();
        await expect(page.locator('[data-feature-block]')).toHaveCount(3);
        await expect(page.locator('.lp-feature-more__tiles a')).toHaveCount(7);
        await expect(
            page.locator('.lp-home-closing').getByRole('link', {
                name: 'Join the waitlist',
            }),
        ).toBeVisible();

        const text = await page.locator('main').innerText();
        expect(text).not.toContain('Dev Project');
        expect(text).not.toMatch(/@example\.com|@loupe\.test/);
    });

    test('a click on the empty area of a tile opens its page, and focus rings the whole tile', async ({
        page,
    }) => {
        await page.goto('/features/inbox');

        const tile = page.locator('.lp-feature-more__tiles a', {
            hasText: 'Board',
        });
        await tile.focus();
        await page.keyboard.press('Shift+Tab');
        await page.keyboard.press('Tab');
        const ring = await tile.evaluate((element) => {
            const style = getComputedStyle(element);

            return {
                outline: style.outlineStyle !== 'none',
                shadow: style.boxShadow !== 'none',
            };
        });
        expect(ring.outline || ring.shadow).toBe(true);

        await tile.click({ position: { x: 4, y: 4 } });
        await expect(page).toHaveURL(/\/features\/board$/, { timeout: 30_000 });
    });

    test('the docs button leads to the docs page of the feature', async ({
        page,
    }) => {
        await page.goto('/features/interactive-sessions');

        await expect(
            page.getByRole('link', { name: 'Read the docs' }),
        ).toHaveAttribute('href', /\/docs\/using\/interactive-sessions\/$/);
    });

    test('the Features menu lists the eight features and marks the current one', async ({
        page,
    }) => {
        await page.goto('/features/inbox');

        await page
            .locator('.lp-home-nav__links')
            .getByRole('button', { name: 'Features' })
            .click();
        const panel = page.locator('#site-features');
        await expect(panel).toBeVisible();
        await expect(panel.locator('a.lp-features-panel__item')).toHaveCount(8);
        await expect(
            panel.getByRole('link', { name: 'See every feature' }),
        ).toBeVisible();
        await expect(panel.locator('[aria-current="page"]')).toContainText(
            'Inbox',
        );

        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
    });

    test('the Features menu opens with no movement when motion is reduced', async ({
        page,
    }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/features/inbox');

        await page
            .locator('.lp-home-nav__links')
            .getByRole('button', { name: 'Features' })
            .click();
        const panel = page.locator('#site-features');
        await expect(panel).toBeVisible();
        const translate = await panel.evaluate(
            (element) => getComputedStyle(element).translate,
        );
        expect(translate).toBe('none');
    });

    test('the footer link opens the index with the eight tiles', async ({
        page,
    }) => {
        await page.goto('/privacy');

        await page
            .locator('.lp-home-footer')
            .getByRole('link', { name: 'Features', exact: true })
            .click();

        await expect(page).toHaveURL(/\/features$/, { timeout: 30_000 });
        for (const slug of SLUGS) {
            await expect(
                page.locator(
                    `main a.lp-feature-tile[href$="/features/${slug}"]`,
                ),
            ).toHaveCount(1);
        }
    });
});

test.describe('phone, signed out', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('the menu panel lists the features and the sign-in actions, and the X closes it', async ({
        page,
    }) => {
        await page.goto('/features/board');

        const toggle = page.getByRole('button', { name: 'Menu' });
        await toggle.click();
        const panel = page.locator('#site-menu');
        await expect(panel).toBeVisible();
        await expect(panel.locator('a.lp-site-menu__feature')).toHaveCount(8);
        for (const name of [
            'See every feature',
            'Docs',
            'Sign in',
            'Join the waitlist',
        ]) {
            await expect(
                panel.getByRole('link', { name, exact: true }),
            ).toBeVisible();
        }
        await expect(
            panel.getByRole('link', { name: 'Go to app' }),
        ).toBeHidden();

        await toggle.click();
        await expect(panel).toBeHidden();
    });
});
