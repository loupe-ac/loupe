/**
 * End-to-end coverage for the first-run wizard (/welcome): a fresh account is
 * routed there after verifying its email, creates its first project, sees what
 * Loupe knows about its machine, and can skip out at either step.
 */

import { expect } from '@playwright/test';
import { hubStubbedTest as test } from '../fixtures';
import { registerFreshUser, submitRedirectingForm } from '../helpers';

test.describe('first-run wizard', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('create a project, then see what Loupe sees on the machine step', async ({
        page,
        request,
    }) => {
        const email = `e2e-wizard-full-${Date.now()}@example.com`;
        await registerFreshUser(page, request, {
            email,
            password: 'e2e_password_123',
        });

        await expect(page).toHaveURL(/\/welcome$/);
        await expect(page.locator('ol[data-wizard-step="1"]')).toBeVisible();
        await page.getByLabel(/Project name/i).fill('Wizard project');
        await submitRedirectingForm(
            page,
            page.getByRole('button', { name: 'Create project' }),
            '/welcome/project',
        );

        await expect(page).toHaveURL(/\/welcome\/connect$/);
        await expect(page.locator('ol[data-wizard-step="2"]')).toBeVisible();
        await expect(page.locator('ol[data-wizard-step] > li')).toHaveCount(5);
        await expect(
            page.getByRole('heading', { name: 'Connect your machine' }),
        ).toBeVisible();

        await expect(
            page.locator('[data-testid="wizard-install-command"]'),
        ).toContainText(/curl -fsSL https?:\/\/[^ ]+\/install\.sh \| sh/);
        const initCommand = page.locator('[data-testid="wizard-init-command"]');
        await expect(initCommand).toContainText(
            /^loupe init --project [0-9a-f-]{36}$/,
        );

        const panel = page.locator('[data-wizard-readiness]');
        await expect(panel).toHaveAttribute('aria-live', 'polite');
        await expect(panel.locator('[data-readiness-row]')).toHaveCount(4);
        await expect(panel.getByText('Waiting')).toHaveCount(4);

        // No bridge runs in the suite, so the way on stays shut.
        await expect(
            page.getByRole('button', { name: 'Continue to the Workshop' }),
        ).toBeDisabled();
        await expect(
            page.getByRole('button', { name: 'Skip for now' }),
        ).toBeVisible();

        const gone = await page.goto('/welcome/widget');
        expect(gone?.status()).toBe(404);
    });

    test('skip from the first step', async ({ page, request }) => {
        const email = `e2e-wizard-skip-first-${Date.now()}@example.com`;
        await registerFreshUser(page, request, {
            email,
            password: 'e2e_password_123',
        });

        await expect(page).toHaveURL(/\/welcome$/);
        // This button sits inside the project form's action row and submits a
        // separate form through `form=`, because a nested <form> is invalid
        // HTML. Nothing else proves that wiring still submits.
        await submitRedirectingForm(
            page,
            page.getByRole('button', { name: 'Skip setup' }),
            '/welcome/skip',
        );
        await expect(page).toHaveURL(/\/projects$/);

        await page.goto('/welcome');
        await expect(page).not.toHaveURL(/\/welcome/);
    });

    test('skip from the connect step', async ({ page, request }) => {
        const email = `e2e-wizard-skip-${Date.now()}@example.com`;
        await registerFreshUser(page, request, {
            email,
            password: 'e2e_password_123',
        });

        await expect(page).toHaveURL(/\/welcome$/);
        await page.getByLabel(/Project name/i).fill('Skipped project');
        await submitRedirectingForm(
            page,
            page.getByRole('button', { name: 'Create project' }),
            '/welcome/project',
        );
        await expect(page).toHaveURL(/\/welcome\/connect$/);

        await submitRedirectingForm(
            page,
            page.getByRole('button', { name: 'Skip for now' }),
            '/welcome/skip',
        );
        await expect(page).toHaveURL(/\/projects$/);

        await page.goto('/welcome');
        await expect(page).not.toHaveURL(/\/welcome/);
    });
});
