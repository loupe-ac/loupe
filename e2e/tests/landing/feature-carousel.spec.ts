/**
 * The feature carousel of the home page: each open item links to its feature
 * page, the slides are the hero pictures, and the links are counted.
 */

import { expect, type Page } from '@playwright/test';
import { hubStubbedTest as test } from '../fixtures';

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1280, height: 900 },
});

const TRACKER = `
    window.umami = {
        track: (name, data) =>
            sessionStorage.setItem('tracked', JSON.stringify({ name, data })),
    };
`;

async function openItem(page: Page, name: RegExp): Promise<void> {
    const item = page.getByRole('button', { name });
    await item.click();
    await expect(item).toHaveAttribute('aria-pressed', 'true');
}

test('the card link of the open item opens its feature page', async ({
    page,
}) => {
    await page.goto('/');
    await openItem(page, /^Document review/);

    await page
        .locator('#features')
        .getByRole('link', { name: 'Learn more →' })
        .and(page.locator('.lp-home-feature-card__link:visible'))
        .click();

    await expect(page).toHaveURL(/\/features\/document-review$/, {
        timeout: 30_000,
    });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});

test('the caption link of the open slide opens its feature page', async ({
    page,
}) => {
    await page.goto('/');
    await openItem(page, /^Document review/);

    await page
        .locator('.lp-home-slide[aria-hidden="false"]')
        .getByRole('link', { name: 'See how it works →' })
        .click();

    await expect(page).toHaveURL(/\/features\/document-review$/, {
        timeout: 30_000,
    });
});

test('closed items stay short and the list and the slide share one height', async ({
    page,
}) => {
    await page.goto('/');

    const panels = page.locator('.lp-home-feature-card__panel');
    await expect(panels).toHaveCount(8);
    await expect(panels.locator(':scope:visible')).toHaveCount(1);
    await expect(panels.first()).toBeVisible();
    for (const index of [1, 2, 3, 4, 5, 6, 7]) {
        await expect(panels.nth(index)).toBeHidden();
    }

    const heights = async () =>
        page.evaluate(() => ({
            list: document
                .querySelector('.lp-home-features__list')!
                .getBoundingClientRect().height,
            stage: document
                .querySelector('.lp-home-stage')!
                .getBoundingClientRect().height,
        }));
    for (const name of [/^Board/, /^Inbox/, /^Analytics/]) {
        await openItem(page, name);
        const { list, stage } = await heights();
        expect(Math.abs(list - stage)).toBeLessThanOrEqual(1);
    }
});

test('each slide is the picture of its feature and has no drawn window', async ({
    page,
}) => {
    await page.goto('/');

    const names = [
        /^Board/,
        /^Workflow rules/,
        /^Interactive sessions/,
        /^Document review/,
        /^Inbox/,
        /^Agent review/,
        /^Live review/,
        /^Analytics/,
    ];
    for (const name of names) {
        await openItem(page, name);
        const slide = page.locator('.lp-home-slide[aria-hidden="false"]');
        await expect(slide).toHaveCount(1);
        await expect(slide.locator('.lp-shot')).toBeVisible();
        await expect(slide.locator('.lp-home-window__bar')).toHaveCount(0);
        await expect(slide.locator('.lp-home-caption')).toBeVisible();
        const link = slide.getByRole('link', { name: 'See how it works →' });
        await expect(link).toHaveAttribute('href', /^\/features\/[a-z-]+$/);
    }
    await expect(page.locator('.lp-home-window')).toHaveCount(0);
});

test('a click on Learn more counts one feature-click for the feature', async ({
    page,
}) => {
    await page.addInitScript(TRACKER);
    await page.goto('/');
    await openItem(page, /^Inbox/);

    await page.locator('.lp-home-feature-card__link:visible').click();
    await expect(page).toHaveURL(/\/features\/inbox$/, { timeout: 30_000 });

    const tracked = await page.evaluate(() =>
        JSON.parse(sessionStorage.getItem('tracked') ?? 'null'),
    );
    expect(tracked).toEqual({
        name: 'feature-click',
        data: { button: 'carousel-card', feature: 'inbox' },
    });
});

test('a click on the caption link counts the caption spot', async ({
    page,
}) => {
    await page.addInitScript(TRACKER);
    await page.goto('/');
    await openItem(page, /^Agent review/);

    await page
        .locator('.lp-home-slide[aria-hidden="false"]')
        .getByRole('link', { name: 'See how it works →' })
        .click();
    await expect(page).toHaveURL(/\/features\/agent-review$/, {
        timeout: 30_000,
    });

    const tracked = await page.evaluate(() =>
        JSON.parse(sessionStorage.getItem('tracked') ?? 'null'),
    );
    expect(tracked).toEqual({
        name: 'feature-click',
        data: { button: 'carousel-caption', feature: 'agent-review' },
    });
});
