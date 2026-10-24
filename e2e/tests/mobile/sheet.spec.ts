/**
 * The Sheet specimen of the styleguide at a phone and a tablet viewport with a
 * touch pointer. The styleguide is a dev-only page and needs no account.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import { hubStubbedTest as test, suppressToolbar } from '../fixtures';

const VIEWPORTS = [
    { name: 'phone', viewport: { width: 390, height: 844 } },
    { name: 'tablet', viewport: { width: 820, height: 1180 } },
];

async function settled(sheet: Locator): Promise<void> {
    await sheet.evaluate((dialog) =>
        Promise.all(dialog.getAnimations().map((a) => a.finished)),
    );
}

for (const { name, viewport } of VIEWPORTS) {
    test.describe(`the sheet on a ${name}`, () => {
        test.use({
            storageState: { cookies: [], origins: [] },
            viewport,
            hasTouch: true,
            isMobile: true,
        });

        test.beforeEach(async ({ page }) => {
            await suppressToolbar(page);
            await page.goto('/styleguide');
            // The token bars and some specimens overflow a phone, which widens the layout viewport.
            await page.addStyleTag({
                content:
                    '[data-token-group], [data-component]:not([data-component="Sheet"]) { display: none !important; }',
            });
        });

        const parts = (page: Page) => {
            const section = page.locator('[data-component="Sheet"]');
            const sheet = section.locator('dialog.lp-sheet');
            return {
                section,
                sheet,
                back: sheet.getByRole('button', { name: 'Back' }),
                close: sheet.getByRole('button', { name: 'Close' }),
                menu: sheet.locator('[data-sheet-view="menu"]'),
                versions: sheet.locator('[data-sheet-view="versions"]'),
            };
        };

        test('a drill-down shows one view and the back button walks back', async ({
            page,
        }) => {
            const { sheet, back, menu, versions } = parts(page);
            await expect(menu).toBeVisible();
            await expect(versions).toBeHidden();
            await expect(back).toBeHidden();

            await sheet.getByRole('button', { name: 'Versions' }).click();
            await expect(versions).toBeVisible();
            await expect(menu).toBeHidden();
            await expect(back).toBeVisible();

            await back.click();
            await expect(menu).toBeVisible();
            await expect(versions).toBeHidden();
            await expect(back).toBeHidden();
        });

        test('the sheet opens at the bottom, resets its view and closes', async ({
            page,
        }) => {
            const { section, sheet, close, menu, versions, back } = parts(page);
            const trigger = section.getByRole('button', {
                name: 'Open the sheet',
            });

            await sheet.getByRole('button', { name: 'Versions' }).click();
            await expect(versions).toBeVisible();
            await close.click();
            await expect(sheet).not.toHaveAttribute('open');

            await trigger.click();
            await expect(sheet).toHaveAttribute('open');
            expect(await sheet.evaluate((d) => d.matches(':modal'))).toBe(true);
            await settled(sheet);
            const box = await sheet.boundingBox();
            expect(box!.x).toBeCloseTo(0, 0);
            expect(box!.width).toBeCloseTo(viewport.width, 0);
            expect(box!.y + box!.height).toBeCloseTo(viewport.height, 0);
            await expect(menu).toBeVisible();
            await expect(versions).toBeHidden();
            await expect(back).toBeHidden();

            await page.keyboard.press('Escape');
            await expect(sheet).not.toHaveAttribute('open');
            await expect(trigger).toBeFocused();

            await trigger.click();
            await expect(sheet).toHaveAttribute('open');
            await settled(sheet);
            await close.click();
            await expect(sheet).not.toHaveAttribute('open');
        });
    });
}
