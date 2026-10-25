/**
 * Browser coverage for a push device that stopped working: the settings page
 * shows the Stopped working chip and no Send test button, the inbox shows the
 * warning, and Dismiss hides the warning while the chip stays.
 */

import { expect, type APIRequestContext, type Page } from '@playwright/test';
import {
    hubStubbedTest as test,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eStoppedDevice1!';

async function setInboxFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function signInWithStoppedDevice(
    page: Page,
    tag: string,
): Promise<{ projectId: string; deviceId: string }> {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setInboxFlag(page.request, true);
    const email = `e2e+stopped+device+${tag}+${RUN}@example.com`;
    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Stopped Device', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });

    const { projectId } = await (
        await page.request.post('/dev/seed/inbox')
    ).json();
    const seeded = await page.request.post('/dev/seed/stopped-push-device', {
        form: { label: 'Safari on iPad' },
    });
    expect(seeded.status()).toBe(201);
    const { deviceId } = await seeded.json();

    return { projectId, deviceId };
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setInboxFlag(request, false);
});

test('a stopped device shows the chip and the warning, and Dismiss hides only the warning', async ({
    page,
}, testInfo) => {
    const { projectId, deviceId } = await signInWithStoppedDevice(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );
    const row = page.locator(`[data-device-id="${deviceId}"]`);

    await page.goto('/account/notifications');
    await expect(row.getByTestId('push-device-stopped-chip')).toHaveText(
        'Stopped working',
    );
    await expect(row).toContainText('Stopped working since 20 Oct 2026');
    await expect(row.getByRole('button', { name: 'Remove' })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Send test' })).toHaveCount(0);

    const inboxPath = `/projects/${projectId}/inbox`;
    await page.goto(inboxPath);
    const warning = page.locator(
        `turbo-frame#push-device-warning [data-device-id="${deviceId}"]`,
    );
    await expect(warning).toContainText(
        'Your push device Safari on iPad stopped working on 20 Oct 2026',
    );
    await warning
        .getByRole('link', { name: 'Open notification settings' })
        .click();
    await expect(page).toHaveURL(/\/account\/notifications$/);

    await page.goto(inboxPath);
    await warning.getByRole('button', { name: 'Dismiss' }).click();

    await expect(page).toHaveURL(new RegExp(`${inboxPath}$`));
    await expect(
        page.locator('turbo-frame#push-device-warning [data-device-id]'),
    ).toHaveCount(0);

    await page.goto('/account/notifications');
    await expect(row.getByTestId('push-device-stopped-chip')).toBeVisible();
});

test('the warning shows on the account inbox too', async ({
    page,
}, testInfo) => {
    const { deviceId } = await signInWithStoppedDevice(
        page,
        testInfo.testId.replace(/[^a-z0-9]/gi, ''),
    );

    await page.goto('/account/inbox');

    await expect(
        page.locator(
            `turbo-frame#push-device-warning [data-device-id="${deviceId}"]`,
        ),
    ).toBeVisible();
});
