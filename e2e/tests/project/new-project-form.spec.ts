import { expect } from '@playwright/test';
import { registerAndVerify, submitRedirectingForm } from '../helpers';
import {
    hubStubbedTest as test,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The create-project form lives in a dialog. The whole dashed tile, the header
 * button and the switcher entry all open it, and the caret lands in the name field.
 */
test('the tile, the header button and the switcher entry open the new-project dialog', async ({
    page,
    request,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndVerify(page, request, {
        email: `e2e+new-project+${Date.now()}@example.com`,
        password: 'E2eNewProject1!',
    });

    await page.goto('/projects');
    const dialog = page.getByRole('dialog', { name: 'New project' });
    await expect(dialog).toBeHidden();

    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i })
        .click();
    await expect(dialog).toBeVisible();
    await expect(page.getByLabel('Project name')).toBeFocused();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();

    const tile = page.locator('.lp-project-new');
    const box = (await tile.boundingBox())!;
    await page.mouse.click(box.x + 8, box.y + 8);
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();

    await page.goto('/projects?new=1');
    await expect(dialog).toBeVisible();
});

test('project descriptions persist on tiles and can be edited or cleared', async ({
    page,
    request,
}) => {
    // Registration, five viewports and three form round trips outrun 30s on a slow runner.
    test.slow();
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndVerify(page, request, {
        email: `e2e+project-description+${Date.now()}@example.com`,
        password: 'E2eProjectDescription1!',
    });
    await page.goto('/projects');
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i })
        .click();
    const name = `Purpose ${'x'.repeat(90)}`;
    const description = `Review the portal.\n${'y'.repeat(400)}`;
    await page.getByLabel('Project name', { exact: true }).fill(name);
    await page
        .getByLabel('Description (optional)', { exact: true })
        .fill(description);
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Add project', exact: true }),
        '/projects?page=1',
    );
    await expect(page.locator('[data-workshop]')).toBeVisible();
    await expect(page.locator('.lp-sidebar__switcher-name')).toHaveText(name);
    await expect(page).toHaveURL(/\/projects\/[^/]+$/);
    await page.goto('/projects');
    const tile = page.locator('[data-project-id]').filter({ hasText: name });
    await expect(tile.locator('.lp-project-row__description')).toHaveText(
        description,
    );
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect
            .poll(() =>
                tile.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            )
            .toBeLessThanOrEqual(1);
        await expect
            .poll(() =>
                page.evaluate(() => document.documentElement.scrollWidth),
            )
            .toBeLessThanOrEqual(width);
        await tile.scrollIntoViewIfNeeded();
        await page.screenshot({
            path: `/tmp/loupe-project-description-${width}.png`,
            animations: 'disabled',
        });
    }
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await expect
        .poll(() =>
            tile.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        )
        .toBeLessThanOrEqual(1);
    const editLink = tile.getByRole('link', {
        name: `Edit ${name}`,
        exact: true,
    });
    const editPath = (await editLink.getAttribute('href'))!;
    await editLink.click();
    await expect(
        page.getByLabel('Description (optional)', { exact: true }),
    ).toHaveValue(description);
    await page
        .getByLabel('Description (optional)', { exact: true })
        .fill('Updated purpose');
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Save changes', exact: true }),
        editPath,
    );
    await expect(tile.locator('.lp-project-row__description')).toHaveText(
        'Updated purpose',
    );
    await editLink.click();
    await page.getByLabel('Description (optional)', { exact: true }).fill('');
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Save changes', exact: true }),
        editPath,
    );
    await expect(tile).toBeVisible();
    await expect(tile.locator('.lp-project-row__description')).toHaveCount(0);
});
