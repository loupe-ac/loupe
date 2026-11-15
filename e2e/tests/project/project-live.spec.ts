/**
 * Browser coverage for the live project pages. A rename saved in one browser
 * shows in the projects list of a second browser with no reload, and the open
 * project form of the second browser keeps its typed text and shows a band
 * with a link that opens the current settings. The hub delivers the signals,
 * so the run needs a Mercure hub the browser can reach.
 */

import { test, expect, type Page } from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eProjectLive1!';

// Each test signs in two browsers beside other workers, which fills the
// default budget on a loaded runner.
test.slow();

test('a rename saved in one browser shows in the projects list and the open form of another', async ({
    browser,
    request,
}) => {
    const email = `e2e+project-live+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Project Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email, PASSWORD);
    const seeded = await editor.request.post('/dev/seed/document', {
        form: { title: 'E2E Project Live', markdown: '# Live' },
    });
    expect(seeded.status()).toBe(201);
    const projectId: string = (await seeded.json()).projectId;
    const editUrl = `/projects/${projectId}/edit`;
    const tile = (page: Page) =>
        page.locator(`[data-live-row="${projectId}"] .lp-project-row__name`);

    const listWatcher = await signedInPage(browser, email, PASSWORD);
    await listWatcher.goto('/projects');
    const before = (await tile(listWatcher).textContent())?.trim();
    expect(before).toBeTruthy();

    const formWatcher = await signedInPage(browser, email, PASSWORD);
    await formWatcher.goto(editUrl);
    await formWatcher.getByLabel('Description').fill('typed and not saved');
    const band = formWatcher.getByTestId('live-form-band');
    await expect(band).toBeHidden();

    // A full navigation would drop this marker, and an in-place update keeps it.
    await listWatcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });

    // The hub keeps no history, so a save before a watcher connects is lost.
    // Each attempt saves a new name until both watchers have heard one.
    let attempt = 0;
    await expect(async () => {
        attempt += 1;
        const renamed = `Renamed ${RUN} ${attempt}`;
        await editor.goto(editUrl);
        await editor.getByLabel('Project name').fill(renamed);
        await editor.getByRole('button', { name: 'Save changes' }).click();
        await expect(editor).toHaveURL(/\/projects$/);

        await expect(tile(listWatcher)).toHaveText(renamed, { timeout: 5_000 });
        await expect(band).toBeVisible({ timeout: 5_000 });
    }).toPass({ timeout: 90_000, intervals: [1_000] });

    expect(
        await listWatcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await expect(band.getByRole('link')).toHaveAttribute('target', '_blank');
    await expect(formWatcher.getByLabel('Description')).toHaveValue(
        'typed and not saved',
    );
});
