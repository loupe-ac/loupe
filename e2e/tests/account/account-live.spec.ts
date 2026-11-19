/**
 * An account page follows the account live: a change made in another browser
 * of the same account shows up without a reload. The hub delivers the signal,
 * so the run needs a Mercure hub the browser can reach. Global setup turns the
 * live updates flag on for the whole run.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
    type Page,
} from '@playwright/test';
import { signedInPage } from '../fixtures';
import { coverageScaled } from '../timeouts';

test.describe.configure({ mode: 'default' });

const RUN = Date.now();
const PASSWORD = 'E2eAccountLive1!';
const LIVE = { timeout: coverageScaled(60_000) };
const ACT = { timeout: coverageScaled(30_000) };

const register = async (
    request: APIRequestContext,
    tag: string,
): Promise<string> => {
    const email = `e2e+accountlive+${tag}+${RUN}+r${test.info().repeatEachIndex}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Account Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    return email;
};

/** Opens the page in a browser of its own, and waits until the hub answers its subscription. */
async function openWatcher(
    browser: Browser,
    email: string,
    path: string,
): Promise<Page> {
    // The hub cookie is one per browser, so the watcher has its own context.
    const watcher = await signedInPage(browser, email, PASSWORD);
    const subscribed = watcher.waitForResponse(
        (response) => response.url().includes('/.well-known/mercure'),
        LIVE,
    );
    await watcher.goto(path);
    await subscribed;
    // The hub keeps no history, so a change made before the subscription opens is lost.
    await watcher.waitForTimeout(1000);

    return watcher;
}

async function createKey(actor: Page, name: string): Promise<void> {
    await actor.goto('/account/api-keys');
    await actor.getByRole('button', { name: 'Create key' }).first().click();
    await actor.getByLabel('Name', { exact: true }).fill(name);
    await actor.getByRole('button', { name: 'Create key' }).last().click();
    await expect(
        actor.getByRole('button', { name: 'I copied the key' }),
    ).toBeVisible(ACT);
}

test.setTimeout(coverageScaled(300_000));

test('a data export that another browser requests appears in the open list', async ({
    browser,
    request,
}) => {
    const email = await register(request, 'export');
    const watcher = await openWatcher(browser, email, '/account/data');
    const actor = await signedInPage(browser, email, PASSWORD);
    await expect(
        watcher.locator('#account-exports .lp-identity-row'),
    ).toHaveCount(0);

    await actor.goto('/account/data');
    await actor.getByRole('button', { name: 'Request export' }).click();
    await expect(actor.locator('.lp-flash--success')).toBeVisible(ACT);

    await expect(
        watcher.locator('#account-exports .lp-identity-row'),
    ).toHaveCount(1, LIVE);
    await actor.context().close();
    await watcher.context().close();
});

test('a key that another browser creates appears, and an open create dialog holds the list', async ({
    browser,
    request,
}) => {
    const email = await register(request, 'keys');
    const watcher = await openWatcher(browser, email, '/account/api-keys');
    const actor = await signedInPage(browser, email, PASSWORD);
    const rows = watcher.locator('#api-keys-list [data-api-key]');
    await expect(rows).toHaveCount(0);

    await createKey(actor, 'First key');
    await expect(rows).toHaveCount(1, LIVE);

    await watcher.getByRole('button', { name: 'Create key' }).first().click();
    await expect(watcher.locator('dialog[open]')).toBeVisible(ACT);
    await createKey(actor, 'Second key');
    await watcher.waitForTimeout(coverageScaled(3_000));
    await expect(rows).toHaveCount(1);
    await expect(watcher.locator('dialog[open]')).toBeVisible();

    await watcher.getByRole('button', { name: 'Cancel' }).click();
    await expect(rows).toHaveCount(2, LIVE);
    await actor.context().close();
    await watcher.context().close();
});

test('a profile that changes elsewhere shows a band and keeps the typed text', async ({
    browser,
    request,
}) => {
    const email = await register(request, 'profile');
    const watcher = await openWatcher(browser, email, '/account/profile');
    const actor = await signedInPage(browser, email, PASSWORD);
    const field = watcher.getByLabel('Display name', { exact: true });
    await field.fill('Typed here');
    await expect(watcher.getByTestId('profile-changed-band')).toBeHidden();

    await actor.goto('/account/profile');
    await actor
        .getByLabel('Display name', { exact: true })
        .fill('Renamed elsewhere');
    await actor.getByRole('button', { name: 'Save' }).click();
    await expect(actor.locator('.lp-flash--success')).toBeVisible(ACT);

    const band = watcher.getByTestId('profile-changed-band');
    await expect(band).toBeVisible(LIVE);
    await expect(band.getByRole('link')).toHaveAttribute('target', '_blank');
    await expect(field).toHaveValue('Typed here');
    await actor.context().close();
    await watcher.context().close();
});
