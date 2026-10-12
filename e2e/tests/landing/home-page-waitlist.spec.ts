/**
 * The "Notify me" form and the waitlist buttons of the home page. They need a
 * closed registration cap, so this file runs in the serial `waitlist` project,
 * and it closes and reopens the cap itself.
 */

import { expect, type Page } from '@playwright/test';
import { ADMIN, setRegistrationCap } from '../admin-helpers';
import { createTest } from '../fixtures';

const test = createTest(ADMIN);
const RUN = Date.now();

test.describe.configure({ mode: 'serial' });

let guest: Page;
let capClosed = false;

test.beforeAll(async ({ browser }) => {
    guest = await browser.newPage({
        storageState: { cookies: [], origins: [] },
    });
});

test.afterAll(async ({ browser, workerStorageState }) => {
    await guest.close();
    if (capClosed) {
        const admin = await browser.newPage({
            storageState: workerStorageState,
        });
        await setRegistrationCap(admin, 0);
        await admin.close();
    }
});

async function openAsk(page: Page): Promise<void> {
    await page.goto('/');
    await page.getByRole('button', { name: /Use something else\?/ }).click();
}

async function entryRow(admin: Page, email: string) {
    await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');

    return admin.locator('tr[data-waitlist-entry-id]', { hasText: email });
}

test('a waitlist button leads a visitor to the waitlist and they join', async ({
    page: admin,
}) => {
    await setRegistrationCap(admin, 1);
    capClosed = true;
    const email = `e2e-home-join-${RUN}@example.com`;

    await guest.goto('/');
    await guest
        .getByRole('link', { name: 'Join the private beta waitlist' })
        .click();
    await expect(guest).toHaveURL(/\/waitlist$/);
    await guest.getByLabel('Email address').fill(email);
    await guest.getByRole('button', { name: 'Join the waitlist' }).click();
    await expect(guest.getByText("You're on the list")).toBeVisible();

    await expect(await entryRow(admin, email)).toHaveCount(1);
});

test('Notify me keeps the picks and the free text next to the email', async ({
    page: admin,
}) => {
    const email = `e2e-home-picks-${RUN}@example.com`;
    await openAsk(guest);

    await guest.getByLabel('GitLab').check();
    await guest.getByLabel('Cursor').check();
    await guest.getByLabel('Something else').fill('Jujutsu');
    await guest.getByLabel('Email').fill(email);
    await guest.getByRole('button', { name: 'Notify me' }).click();
    await expect(guest.getByText("You're on the list")).toBeVisible();

    const row = await entryRow(admin, email);
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-waitlist-picks]')).toContainText('GitLab');
    await expect(row.locator('[data-waitlist-picks]')).toContainText('Cursor');
    await expect(row.locator('[data-waitlist-picks]')).toContainText('Jujutsu');
    await expect(
        admin.locator('[data-waitlist-pick-counts] [data-pick="gitlab"]'),
    ).not.toContainText(': 0');
    await expect(
        admin.locator('[data-waitlist-pick-counts] [data-pick="cursor"]'),
    ).not.toContainText(': 0');
});

test('an email that is already listed keeps its place and adds picks', async ({
    page: admin,
}) => {
    const email = `e2e-home-merge-${RUN}@example.com`;

    await openAsk(guest);
    await guest.getByLabel('GitLab').check();
    await guest.getByLabel('Email').fill(email);
    await guest.getByRole('button', { name: 'Notify me' }).click();
    await expect(guest.getByText("You're on the list")).toBeVisible();

    await openAsk(guest);
    await guest.getByLabel('Bitbucket').check();
    await guest.getByLabel('Email').fill(email);
    await guest.getByRole('button', { name: 'Notify me' }).click();
    await expect(guest.getByText("You're on the list")).toBeVisible();

    const row = await entryRow(admin, email);
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-waitlist-picks]')).toContainText('GitLab');
    await expect(row.locator('[data-waitlist-picks]')).toContainText(
        'Bitbucket',
    );
});

test('Notify me with no email shows the email error and stores nothing', async ({
    page: admin,
}) => {
    await openAsk(guest);
    await guest.getByLabel('Forgejo').check();
    await guest.getByLabel('Something else').fill(`no-email-${RUN}`);
    await guest.getByRole('button', { name: 'Notify me' }).click();

    await expect(guest.locator('#waitlist-notify')).toContainText(/email/i);
    await expect(guest.getByText("You're on the list")).toBeHidden();

    await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
    await expect(admin.getByText(`no-email-${RUN}`)).toHaveCount(0);
});
