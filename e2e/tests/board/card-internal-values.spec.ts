/**
 * The internal values of a card on the Details tab. They sit in a closed section, read-only,
 * and the card page route refuses a direct write to them.
 */

import { expect } from '@playwright/test';
import { createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-card-internal-values-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

test('the internal values open read-only, and a direct write is refused', async ({
    page,
}) => {
    await suppressWidget(page);

    // A new project binds the Lifecycle template, which declares the internal value.
    await page.goto('/projects');
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: 'New project', exact: true })
        .click();
    await page
        .getByLabel('Project name', { exact: true })
        .fill(`Internal values ${Date.now()}`);
    await page
        .getByRole('button', { name: 'Add project', exact: true })
        .click();
    await expect(page.locator('[data-workshop]')).toBeVisible();
    const projectId = new URL(page.url()).pathname.split('/')[2];

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Internal values card');
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(
        page.getByRole('heading', { name: 'Internal values card' }),
    ).toBeVisible({ timeout: 15_000 });
    const cardId = new URL(page.url()).pathname.split('/').pop();
    expect(cardId).toMatch(/^[0-9a-f-]{36}$/);
    const cardPath = `/projects/${projectId}/board/cards/${cardId}`;

    await page.goto(`${cardPath}?tab=details`);
    const section = page.locator('details[data-card-internal-values]');
    const row = section.locator('#card-value-session-start-approved');
    await expect(section).toBeVisible();
    await expect(section).not.toHaveAttribute('open');
    await expect(row).toBeHidden();

    await section.locator('summary').click();
    await expect(section).toHaveAttribute('open');
    await expect(row).toBeVisible();
    await expect(row.locator('.lp-value-row__value')).toHaveText('Off');
    await expect(row).toContainText('Not set');
    await expect(row.locator('input, button, [role="switch"]')).toHaveCount(0);

    const write = await page.request.post(
        `${cardPath}/values/session-start-approved`,
        { form: { value: '1' }, maxRedirects: 0 },
    );
    expect(write.status()).toBe(404);

    // The section may reopen from memory, so read the row text whether it shows or not.
    await page.goto(`${cardPath}?tab=details`);
    await expect(
        page
            .locator('#card-value-session-start-approved')
            .locator('.lp-value-row__value'),
    ).toHaveText('Off');
    await expect(
        page.locator('#card-value-session-start-approved'),
    ).toContainText('Not set');
});
