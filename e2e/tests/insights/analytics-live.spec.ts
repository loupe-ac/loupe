/**
 * Browser coverage for the live Insights pages: a time bucket rule added in one
 * browser shows in a second browser with no navigation there, and a settings
 * change in one browser raises the band over the settings form of the other.
 * The hub delivers the signal, so the run needs a Mercure hub the browser can
 * reach. The experiment pages have no spec here, because the suite has no way
 * to make an experiment. PHPUnit covers their signals and regions.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
    type Page,
} from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eAnalyticsLive1!';

// Each test signs in two browsers beside three other workers, which fills the
// default budget on a loaded runner.
test.slow();

/** Opens a page and waits until the browser asked the hub to follow its topics. */
async function openLive(page: Page, url: string): Promise<void> {
    const subscribed = page.waitForRequest(
        (request) => new URL(request.url()).pathname === '/.well-known/mercure',
        { timeout: 15000 },
    );
    await page.goto(url);
    await subscribed;
}

async function twoBrowsers(
    browser: Browser,
    request: APIRequestContext,
    name: string,
): Promise<{ editor: Page; watcher: Page; projectId: string }> {
    const email = `e2e+${name}+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Analytics Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email, PASSWORD);
    const seeded = await editor.request.post('/dev/seed/document', {
        form: { title: `E2E Analytics ${name}`, markdown: '# Analytics' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = (await seeded.json()).projectId as string;
    const watcher = await signedInPage(browser, email, PASSWORD);

    return { editor, watcher, projectId };
}

test('a time bucket rule added in one browser shows in another without a reload', async ({
    browser,
    request,
}) => {
    const { editor, watcher, projectId } = await twoBrowsers(
        browser,
        request,
        'buckets',
    );
    const url = `/projects/${projectId}/analytics/time-buckets`;

    await openLive(watcher, url);
    await expect(watcher.locator('[data-bucket-rules-empty]')).toHaveCount(1);

    const loads: string[] = [];
    watcher.on('request', (req) => {
        if (
            req.method() === 'GET' &&
            new URL(req.url()).pathname === url &&
            req.resourceType() === 'document'
        ) {
            loads.push(req.url());
        }
    });

    await editor.goto(url);
    await editor.getByLabel('Pattern', { exact: true }).fill('make *');
    await editor.getByLabel('Bucket', { exact: true }).fill('build');
    await editor.getByRole('button', { name: 'Add the rule' }).click();
    await expect(editor.locator('[data-bucket-rule]')).toHaveCount(1);

    await expect(watcher.locator('[data-bucket-rule]')).toHaveCount(1, {
        timeout: 15000,
    });
    await expect(watcher.locator('[data-bucket-rule-pattern]')).toHaveText(
        'make *',
    );
    await expect(watcher.locator('[data-bucket-rules-empty]')).toHaveCount(0);
    expect(loads).toEqual([]);
});

test('a settings change in one browser raises the band over the form of another', async ({
    browser,
    request,
}) => {
    const { editor, watcher, projectId } = await twoBrowsers(
        browser,
        request,
        'settings',
    );
    const url = `/projects/${projectId}/analytics/reports`;

    await openLive(watcher, url);
    await watcher.getByText('Analysis settings', { exact: true }).click();
    await watcher.getByLabel('Default model').fill('typed-by-the-watcher');
    await expect(
        watcher.locator('[data-analytics-settings-band]'),
    ).toBeHidden();

    await editor.goto(url);
    await editor.getByText('Analysis settings', { exact: true }).click();
    await editor.getByLabel('Programs with subcommands').fill('git');
    await editor.getByRole('button', { name: 'Save the settings' }).click();
    await expect(editor.getByLabel('Programs with subcommands')).toHaveValue(
        'git',
    );

    await expect(watcher.locator('[data-analytics-settings-band]')).toBeVisible(
        { timeout: 15000 },
    );
    await expect(watcher.getByLabel('Default model')).toHaveValue(
        'typed-by-the-watcher',
    );
});
