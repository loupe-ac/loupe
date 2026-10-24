/**
 * The overflow arrows of a tab strip and of the settings menu at a 390px phone.
 *
 * A strip that is wider than its box draws a "›" over the hidden end and a "‹"
 * over the hidden start. The arrows scroll the strip, and the current tab is
 * in view on load. The settings menu is a column from 1024px, so it draws none.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eTabArrows1!';

const test = base.extend<{ projectId: string }>({
    projectId: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+tabarrows+${tag}+${RUN}@example.com`;

            const registered = await page.request.post(
                '/dev/register-and-verify',
                { form: { fullName: 'E2E Arrows', email, password: PASSWORD } },
            );
            expect(registered.status()).toBe(200);

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', { timeout: 20000 });

            const seeded = await page.request.post('/dev/seed/document', {
                form: { title: 'Tab arrows', markdown: '# Arrows' },
            });
            expect(seeded.status()).toBe(201);

            await use((await seeded.json()).projectId as string);
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

const cueOf = (page: Page, strip: string): Locator =>
    page.locator(`.lp-overflow-cue:has(${strip})`);

async function scrollLeftOf(locator: Locator): Promise<number> {
    return locator.evaluate((element) => element.scrollLeft);
}

test('the analytics tabs draw arrows that scroll the strip', async ({
    page,
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/analytics/metrics`);
    const strip = page.locator('.lp-analytics-tabs');
    const cue = cueOf(page, '.lp-analytics-tabs');
    const next = cue.locator('.lp-overflow-cue__arrow--next');
    const prev = cue.locator('.lp-overflow-cue__arrow--prev');

    await expect(next).toBeVisible();
    await expect(prev).toBeHidden();
    await expect(next).toHaveAccessibleName('Scroll forward');
    expect(await scrollLeftOf(strip)).toBe(0);

    await next.tap();

    await expect(prev).toBeVisible();
    await expect.poll(() => scrollLeftOf(strip)).toBeGreaterThan(0);
});

test('the current tab is in view on load', async ({ page, projectId }) => {
    await page.goto(`/projects/${projectId}/analytics/time-buckets`);
    const strip = page.locator('.lp-analytics-tabs');
    const current = strip.locator('.lp-tabs__tab[aria-current="page"]');

    await expect(current).toBeVisible();
    await expect
        .poll(async () => {
            const tab = (await current.boundingBox())!;
            const box = (await strip.boundingBox())!;

            const arrow = (await cueOf(page, '.lp-analytics-tabs')
                .locator('.lp-overflow-cue__arrow--prev')
                .boundingBox())!;

            return (
                tab.x >= arrow.x + arrow.width - 1 &&
                tab.x + tab.width <= box.x + box.width + 1
            );
        })
        .toBe(true);
    expect(await scrollLeftOf(strip)).toBeGreaterThan(0);
    await expect(
        cueOf(page, '.lp-analytics-tabs').locator(
            '.lp-overflow-cue__arrow--prev',
        ),
    ).toBeVisible();
});

test('the settings menu draws arrows on a phone and none on a desktop', async ({
    page,
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/edit`);
    const nav = page.locator('.lp-settings-nav');
    const cue = cueOf(page, '.lp-settings-nav');
    const next = cue.locator('.lp-overflow-cue__arrow--next');

    await expect(next).toBeVisible();
    await next.tap();
    await expect(cue.locator('.lp-overflow-cue__arrow--prev')).toBeVisible();
    await expect.poll(() => scrollLeftOf(nav)).toBeGreaterThan(0);

    await page.setViewportSize({ width: 1100, height: 900 });
    await expect(cue.locator('.lp-overflow-cue__arrow')).toHaveCount(2);
    await expect(cue.locator('.lp-overflow-cue__arrow--next')).toBeHidden();
    await expect(cue.locator('.lp-overflow-cue__arrow--prev')).toBeHidden();
});
