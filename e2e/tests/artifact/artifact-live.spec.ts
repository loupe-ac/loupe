/**
 * The artifact pages follow their project live. A new version shows a band
 * with a link while the prototype stays as it is, and the artifacts list gains
 * the new row in place. The hub delivers the signal, so the run needs a
 * Mercure hub the browser can reach, and the spec turns the live updates flag
 * on.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
    type Page,
} from '@playwright/test';
import { agentAccessToken, signedInPage } from '../fixtures';
import { skipProjectSetup } from '../helpers';
import { coverageScaled } from '../timeouts';
import { seedDraftDesign } from '../review/draft-design';

test.describe.configure({ mode: 'serial' });

const RUN = Date.now();
const PASSWORD = 'E2eArtifactLive1!';
const LIVE = { timeout: coverageScaled(20_000) };

const setFlag = async (
    request: APIRequestContext,
    name: string,
    enabled: boolean,
): Promise<void> => {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
};

/** Opens the page in a browser of its own, and waits until the hub answers its subscription. */
async function openWatcher(
    browser: Browser,
    email: string,
    path: string,
): Promise<Page> {
    const watcher = await signedInPage(browser, email, PASSWORD);
    await watcher.goto(path);
    await skipProjectSetup(watcher);
    await expect(watcher).not.toHaveURL(/\/setup$/);
    const subscribed = watcher.waitForResponse(
        (response) => response.url().includes('/.well-known/mercure'),
        LIVE,
    );
    await watcher.goto(path);
    await expect(watcher).toHaveURL(new RegExp(`${path}$`));
    await subscribed;
    // The hub keeps no history, so a change made before the subscription opens is lost.
    await watcher.waitForTimeout(1000);

    return watcher;
}

async function storeVersion(
    request: APIRequestContext,
    token: string,
    projectId: string,
    fields: Record<string, string>,
    html: string,
): Promise<{ artifactId: string }> {
    const stored = await request.post(
        `/api/v1/bridge/projects/${projectId}/artifacts`,
        {
            headers: { Authorization: `Bearer ${token}` },
            multipart: {
                ...fields,
                'paths[0]': 'index.html',
                'files[0]': {
                    name: 'index.html',
                    mimeType: 'text/html',
                    buffer: Buffer.from(html),
                },
            },
        },
    );
    expect(stored.status()).toBe(201);

    return stored.json();
}

test.setTimeout(coverageScaled(150_000));

test.afterAll(async ({ request }) => {
    await setFlag(request, 'artifacts.enabled', false);
});

test('a new version shows a band with a link and keeps the open prototype, and the list gains the row', async ({
    browser,
    request,
}) => {
    await setFlag(request, 'live_updates.enabled', true);
    await setFlag(request, 'artifacts.enabled', true);
    const email = `e2e+artifactlive+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Artifact Live', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const author = await signedInPage(browser, email, PASSWORD);
    const draft = await seedDraftDesign(author);
    const token = await agentAccessToken(author);
    const { artifactId } = await storeVersion(
        request,
        token,
        draft.projectId,
        { name: 'Live artifact' },
        '<h1>One</h1>',
    );
    const base = `/projects/${draft.projectId}/artifacts`;

    const page = await openWatcher(browser, email, `${base}/${artifactId}`);
    const full = await openWatcher(
        browser,
        email,
        `${base}/${artifactId}/full-screen`,
    );
    const list = await openWatcher(browser, email, base);
    await expect(list.locator('[data-artifact-id]')).toHaveCount(1);
    await expect(page.locator('[data-artifact-live-version]')).toHaveCount(0);
    const frameSource = await page
        .locator('iframe[data-artifact-frame]')
        .getAttribute('src');

    const fullSource = await full
        .locator('iframe[data-artifact-frame]')
        .getAttribute('src');

    await storeVersion(
        request,
        token,
        draft.projectId,
        { artifactId },
        '<h1>Two</h1>',
    );

    const band = page.locator('[data-artifact-live-version]');
    await expect(band).toContainText('Version 2 is out.', LIVE);
    await expect(page.locator('iframe[data-artifact-frame]')).toHaveAttribute(
        'src',
        frameSource ?? '',
    );
    const fullBand = full.locator('[data-artifact-live-version]');
    await expect(fullBand).toContainText('Version 2 is out.', LIVE);
    await expect(full.locator('iframe[data-artifact-frame]')).toHaveAttribute(
        'src',
        fullSource ?? '',
    );
    await expect(
        fullBand.getByRole('link', { name: 'Show version 2' }),
    ).toHaveAttribute('href', `${base}/${artifactId}/versions/2/full-screen`);
    await band.getByRole('link', { name: 'Show version 2' }).click();
    await expect(page).toHaveURL(
        new RegExp(`${base}/${artifactId}/versions/2$`),
    );
    await expect(page.locator('[data-artifact-live-version]')).toHaveCount(0);

    await storeVersion(
        request,
        token,
        draft.projectId,
        { name: 'Second artifact' },
        '<h1>Other</h1>',
    );
    await expect(list.locator('[data-artifact-id]')).toHaveCount(2, LIVE);

    await author.context().close();
    await page.context().close();
    await full.context().close();
    await list.context().close();
});
