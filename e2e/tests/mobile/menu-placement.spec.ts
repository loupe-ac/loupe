/**
 * A menu panel stays inside a phone screen at 200% text: the width is capped
 * and a script moves the panel sideways when an edge lies outside the margin.
 * One menu of each alignment is opened: start, end and stretch.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const test = createTest({
    email: 'e2e-menu-placement@example.com',
    password: 'E2eMenuPlacement1!',
});

test.describe.configure({ mode: 'default' });

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await page.setViewportSize({ width: 390, height: 844 });
});

async function seedReviewPath(page: Page): Promise<string> {
    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Menu Placement Plan',
            markdown: '## Plan\n\nThe first section.',
            revisions: JSON.stringify(['## Plan\n\nThe revised section.']),
        },
    });
    expect(seeded.status()).toBe(201);
    const { projectId, documentId } = await seeded.json();

    return `/projects/${projectId}/documents/${documentId}/review`;
}

async function enlargeText(page: Page): Promise<void> {
    await page.evaluate(() => {
        document.documentElement.style.fontSize = '200%';
    });
}

async function expectPanelOnScreen(panel: Locator, page: Page): Promise<void> {
    await expect(panel).toBeVisible();
    // The open animation moves the panel for 120 ms, so read the settled box.
    await expect
        .poll(async () => {
            const box = await panel.evaluate((element) => {
                const bounds = element.getBoundingClientRect();

                return { left: bounds.left, right: bounds.right };
            });
            const width = await page.evaluate(
                () => document.documentElement.clientWidth,
            );

            return box.left >= 0 && box.right <= width;
        })
        .toBe(true);
}

test('a start-aligned menu stays on screen at enlarged text', async ({
    page,
}) => {
    await page.goto(await seedReviewPath(page));
    await enlargeText(page);
    await page.locator('.lp-revision-notes__trigger').click();
    await expectPanelOnScreen(page.locator('#revision-notes-panel'), page);
});

test('an end-aligned menu stays on screen at enlarged text', async ({
    page,
}) => {
    await page.goto(`${await seedReviewPath(page)}/diff/1/2`);
    await enlargeText(page);
    // On a phone the diff bar folds behind its summary line.
    await page.locator('#diff-bar-line').click();
    await page
        .locator('.lp-diff-bar__views')
        .getByRole('button')
        .first()
        .click();
    await expectPanelOnScreen(page.locator('#diff-views-menu'), page);
});

test('the stretch project switcher stays on screen at enlarged text', async ({
    page,
}) => {
    await page.goto(await seedReviewPath(page));
    await enlargeText(page);
    await page
        .getByRole('button', { name: 'Open navigation', exact: true })
        .click();
    // The drawer slides in for 200 ms, and the panel is placed once on open.
    await expect
        .poll(() =>
            page
                .locator('.lp-sidebar--drawer')
                .evaluate((element) => element.getBoundingClientRect().left),
        )
        .toBe(0);
    await page.locator('.lp-sidebar__switcher').click();
    await expectPanelOnScreen(page.locator('#project-switcher-panel'), page);
});
