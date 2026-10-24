/**
 * The diff page at a phone and a tablet viewport with a touch pointer.
 *
 * Below lg the version bar is one sticky line with the versions, the change
 * count, the position and the two jumps. A tap on the line opens the back link,
 * the pickers and the views under it.
 */

import { expect, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eDiffLine1!';

const VIEWPORTS = [
    { name: 'phone', viewport: { width: 390, height: 844 } },
    { name: 'tablet', viewport: { width: 820, height: 1180 } },
];

const FILLER = '\nA paragraph that fills the screen.\n'.repeat(30);
const VERSION_ONE = `# Plan\n\nThe first change starts here.\n${FILLER}\nThe second change starts here.\n${FILLER}\nThe third change starts here.\n`;
const VERSION_TWO = VERSION_ONE.replace('first change', 'first edit');
const VERSION_THREE = VERSION_TWO.replace(
    'second change',
    'second edit',
).replace('third change', 'third edit');

const LINE = '.lp-diff-bar__line';

async function seedDiff(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Diff line plan',
            markdown: VERSION_ONE,
            revisions: JSON.stringify([VERSION_TWO, VERSION_THREE]),
        },
    });
    expect(response.status()).toBe(201);
    const { projectId, documentId } = await response.json();

    return `/projects/${projectId}/documents/${documentId}/review/diff/1/3`;
}

const test = base.extend<{ diffPath: string }>({
    diffPath: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+diffline+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                {
                    form: {
                        fullName: 'E2E Diff Line',
                        email,
                        password: PASSWORD,
                    },
                },
            );
            expect(registered.status()).toBe(200);
            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', {
                timeout: coverageScaled(20000),
            });

            await use(await seedDiff(page));
        },
        { auto: true },
    ],
});

for (const { name, viewport } of VIEWPORTS) {
    test.describe(`the diff line on a ${name}`, () => {
        test.use({
            storageState: { cookies: [], origins: [] },
            viewport,
            hasTouch: true,
            isMobile: true,
        });

        test('one line names the versions, the count and the position', async ({
            page,
            diffPath,
        }) => {
            await page.goto(diffPath);
            const line = page.locator(LINE);
            await expect(line).toBeVisible();
            await expect(line).toContainText('v1');
            await expect(line).toContainText('v3');
            await expect(line).toContainText('3 changes');

            // The bar is one row: no wrapped control, and the folded part is out.
            const bar = page.locator('.lp-diff-bar');
            const box = await bar.boundingBox();
            expect(box!.height).toBeLessThan(64);
            await expect(page.locator('#diff-from')).toBeHidden();
            await expect(
                bar.getByRole('link', { name: 'Return to document' }),
            ).toBeHidden();

            // The count gives way to the position on screen, and stays the
            // live region a screen reader hears after each jump.
            const counter = page.locator('.lp-diff-nav__count');
            await expect(counter).toHaveCSS('position', 'absolute');
            await expect(counter).toHaveAttribute('aria-live', 'polite');
            const position = page.locator('.lp-diff-nav__position');
            await expect(position).toHaveText('0/3');
            await bar.getByRole('button', { name: 'Next change' }).click();
            await expect(position).toHaveText('1/3');
            await expect(counter).toHaveText('1 of 3 changes');
            await expect(
                bar
                    .getByRole('paragraph')
                    .filter({ hasText: '1 of 3 changes' }),
            ).toHaveCount(1);
            await bar.getByRole('button', { name: 'Next change' }).click();
            await expect(position).toHaveText('2/3');
            await bar.getByRole('button', { name: 'Previous change' }).click();
            await expect(position).toHaveText('1/3');
        });

        test('the line stays in view while the page scrolls', async ({
            page,
            diffPath,
        }) => {
            await page.goto(diffPath);
            await page.evaluate(() => window.scrollTo(0, 1200));
            await page.locator('#diff-hunk-2').scrollIntoViewIfNeeded();
            await expect(page.locator(LINE)).toBeInViewport();
        });

        test('a tap on the line opens the pickers under it', async ({
            page,
            diffPath,
        }) => {
            await page.goto(diffPath);
            const line = page.locator(LINE);
            await expect(line).toHaveAttribute('aria-expanded', 'false');

            await line.tap();
            await expect(line).toHaveAttribute('aria-expanded', 'true');
            const from = page.locator('#diff-from');
            await expect(from).toBeVisible();
            await expect(page.locator('#diff-to')).toBeVisible();
            const lineBox = await line.boundingBox();
            const fromBox = await from.boundingBox();
            expect(fromBox!.y).toBeGreaterThan(
                lineBox!.y + lineBox!.height - 1,
            );

            await from.selectOption('2');
            await expect(page).toHaveURL(/\/diff\/2\/3/, {
                timeout: coverageScaled(15000),
            });
            await expect(page.locator(LINE)).toContainText('v2');

            await page.locator(LINE).tap();
            await page.keyboard.press('Escape');
            await expect(page.locator(LINE)).toHaveAttribute(
                'aria-expanded',
                'false',
            );
            await expect(page.locator('#diff-from')).toBeHidden();
        });
    });
}
