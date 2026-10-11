/**
 * Browser coverage for the preview tokens of a person: the list in Connected
 * apps and the preview sign-in panel of a card. Each test signs up a person of
 * its own, so no test reads tokens that another one minted.
 */

import { expect, type Page } from '@playwright/test';
import {
    agentAccessToken,
    hubStubbedTest as test,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const PASSWORD = 'E2ePreviewTokens1!';

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

async function signUp(page: Page, name: string): Promise<void> {
    await suppressToolbar(page);
    await suppressWidget(page);
    const email = `e2e+preview-tokens+${name}+${crypto.randomUUID()}@example.com`;
    const registered = await page.request.post('/dev/register-and-verify', {
        form: {
            fullName: 'E2E Preview Tokens User',
            email,
            password: PASSWORD,
        },
    });
    expect(registered.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15_000 });
}

/** Creates a card and returns the path of its page. */
async function createCard(
    page: Page,
    projectId: string,
    title: string,
): Promise<string> {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible({
        timeout: 15_000,
    });

    return new URL(page.url()).pathname;
}

async function mintPreviewToken(
    page: Page,
    token: string,
    projectId: string,
    label: string,
    cardPath?: string,
): Promise<void> {
    const response = await page.request.post(
        `/api/projects/${projectId}/preview-tokens`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                label,
                ...(cardPath ? { card: cardPath.split('/').pop() } : {}),
            },
        },
    );
    expect(response.status()).toBe(201);
}

test('a person with no preview token sees the empty line in the widget group', async ({
    page,
}) => {
    await signUp(page, 'empty');

    await page.goto('/account/connected-apps');

    const group = page.locator('[data-preview-widget]');
    await expect(group).toContainText('Site review widget');
    await expect(group).toContainText('No live preview tokens.');
    await expect(group.locator('[data-preview-token]')).toHaveCount(0);
    await expect(group.getByRole('button', { name: 'Revoke all' })).toHaveCount(
        0,
    );
});

test('a person revokes one preview token in Connected apps and another from its card', async ({
    page,
}) => {
    // A sign-up, two cards, three mints and two revokes outlast the default budget.
    test.slow();
    await signUp(page, 'revoke');

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Preview Tokens Project', markdown: '# Preview' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const alphaPath = await createCard(page, projectId, 'Alpha preview');
    const betaPath = await createCard(page, projectId, 'Beta preview');

    const token = await agentAccessToken(page);
    await mintPreviewToken(page, token, projectId, 'pr-alpha', alphaPath);
    await mintPreviewToken(page, token, projectId, 'pr-beta', betaPath);
    await mintPreviewToken(page, token, projectId, 'pr-loose');

    await page.goto('/account/connected-apps');
    const rows = page.locator('[data-preview-widget] [data-preview-token]');
    const alpha = rows.filter({ hasText: 'Alpha preview' });
    const beta = rows.filter({ hasText: 'Beta preview' });
    const loose = rows.filter({ hasText: 'No card' });
    await expect(rows).toHaveCount(3);
    await expect(alpha).toContainText(/#\d+\s*Alpha preview/);
    // The dev seed files every document under its one harness project.
    await expect(alpha).toContainText(
        'e2e-harness · Expires in 14 days · Not used yet',
    );
    await expect(loose).toHaveCount(1);

    await alpha.getByRole('button', { name: 'Revoke' }).click();
    const dialog = alpha.getByRole('dialog');
    await expect(dialog).toContainText('Revoke this preview token?');
    await dialog.getByRole('button', { name: 'Revoke' }).click();

    await expect(
        page.getByText('The preview token no longer works.'),
    ).toBeVisible();
    await expect(alpha).toHaveCount(0);
    await expect(beta).toHaveCount(1);
    await expect(loose).toHaveCount(1);

    await page.goto(betaPath);
    const panel = page.locator('[data-preview-sign-in]');
    await expect(panel).toContainText('Preview sign-in');
    await expect(panel.locator('.lp-status-chip--ok')).toHaveText('Active');
    await expect(
        panel.locator('.lp-card-field').filter({ hasText: 'Expires' }),
    ).toContainText('In 14 days');
    await expect(panel.getByRole('button', { name: 'Revoke' })).toBeVisible();

    await panel.getByRole('link', { name: 'See all preview tokens' }).click();
    await expect(page).toHaveURL('/account/connected-apps');
    await expect(beta).toHaveCount(1);

    await page.goto(betaPath);
    await panel.getByRole('button', { name: 'Revoke' }).click();
    await panel
        .getByRole('dialog')
        .getByRole('button', { name: 'Revoke' })
        .click();

    await expect(
        page.getByText('The preview token no longer works.'),
    ).toBeVisible();
    await expect(page).toHaveURL(betaPath);
    await expect(panel).toContainText('No live preview tokens.');
    await expect(panel.getByRole('button', { name: 'Revoke' })).toHaveCount(0);
});
