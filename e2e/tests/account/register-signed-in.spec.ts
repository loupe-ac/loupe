/**
 * The dev registration endpoint signs the new user in when it gets login=1 and
 * the password matches. A repeated email with a wrong password gets no session.
 */

import { test, expect } from '@playwright/test';

const RUN = Date.now();
const PASSWORD = 'E2eSignedIn1!';

test.use({ storageState: { cookies: [], origins: [] } });

test('login=1 registers the user and opens a signed-in session', async ({
    page,
}) => {
    const response = await page.request.post('/dev/register-and-verify', {
        form: {
            email: `e2e+signed+in+${RUN}@example.com`,
            password: PASSWORD,
            login: 1,
        },
    });
    expect(response.status()).toBe(200);

    await page.goto('/projects');
    await expect(page).toHaveURL(/\/projects/);
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
});

test('a post without login leaves the browser signed out', async ({ page }) => {
    const response = await page.request.post('/dev/register-and-verify', {
        form: {
            email: `e2e+signed+out+${RUN}@example.com`,
            password: PASSWORD,
        },
    });
    expect(response.status()).toBe(200);

    await page.goto('/projects');
    await expect(page).toHaveURL(/\/login/);
});

test('a repeated email with a wrong password gets 403 and no session', async ({
    browser,
    request,
}) => {
    const email = `e2e+signed+wrong+${RUN}@example.com`;
    const created = await request.post('/dev/register-and-verify', {
        form: { email, password: PASSWORD },
    });
    expect(created.status()).toBe(200);

    const context = await browser.newContext();
    const page = await context.newPage();
    const response = await page.request.post('/dev/register-and-verify', {
        form: { email, password: 'WrongPassword1!', login: 1 },
    });
    expect(response.status()).toBe(403);

    await page.goto('/projects');
    await expect(page).toHaveURL(/\/login/);
    await context.close();
});
