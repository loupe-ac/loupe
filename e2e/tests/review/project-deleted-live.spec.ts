/**
 * A page that follows its project live shows the project-deleted band and
 * hides its live content when the project is deleted in another browser. The
 * hub delivers the signal, so the run needs a Mercure hub the browser can
 * reach, and the spec turns the live updates flag on.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
    type Page,
} from '@playwright/test';
import { agentAccessToken, signedInPage } from '../fixtures';
import { skipProjectSetup, submitRedirectingForm } from '../helpers';
import { coverageScaled } from '../timeouts';
import { seedDraftDesign } from './draft-design';

test.describe.configure({ mode: 'serial' });

const RUN = Date.now();
const PASSWORD = 'E2eProjectDeletedLive1!';
const LIVE = { timeout: coverageScaled(20_000) };
const BAND = 'This project was deleted, so this page no longer updates.';

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

const register = async (
    request: APIRequestContext,
    tag: string,
): Promise<string> => {
    await setFlag(request, 'live_updates.enabled', true);
    const email = `e2e+projectdeleted+${tag}+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Project Deleted', email, password: PASSWORD },
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
    // The skip lives in the browser session, so this new context meets the setup page first.
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

/** Deletes the project through its settings page, as the signed-in page. */
async function deleteProject(deleter: Page, projectId: string): Promise<void> {
    await deleter.goto(`/projects/${projectId}/edit`);
    const field = deleter.getByLabel('Project name', { exact: true });
    await expect(field).toHaveValue(/^draft-band-/);
    const name = await field.evaluate((input: HTMLInputElement) => input.value);
    await deleter.getByRole('button', { name: /delete this project/i }).click();
    await deleter.getByLabel(/type the project name/i).fill(name);
    const confirm = deleter.getByRole('button', { name: /i understand/i });
    await expect(confirm).toBeEnabled();
    const action = await confirm.evaluate(
        (button: HTMLButtonElement) => button.form!.action,
    );
    await submitRedirectingForm(deleter, confirm, action);
    await expect(deleter).toHaveURL(/\/projects$/);
}

test.setTimeout(coverageScaled(150_000));

test.afterAll(async ({ request }) => {
    await setFlag(request, 'artifacts.enabled', false);
});

test('the document review page shows the project-deleted band and hides its content', async ({
    browser,
    request,
}) => {
    const email = await register(request, 'review');
    const author = await signedInPage(browser, email, PASSWORD);
    const draft = await seedDraftDesign(author);

    const watcher = await openWatcher(browser, email, draft.reviewUrl);
    const content = watcher.locator('[data-live-content]');
    await expect(content).toBeVisible();
    await expect(watcher.getByText(BAND)).toHaveCount(0);

    await deleteProject(author, draft.projectId);
    await author.context().close();

    await expect(watcher.getByText(BAND)).toBeVisible(LIVE);
    await expect(content).toBeHidden();
    await watcher.context().close();
});

test('the full-screen artifact page shows the project-deleted band', async ({
    browser,
    request,
}) => {
    await setFlag(request, 'artifacts.enabled', true);
    const email = await register(request, 'artifact');
    const author = await signedInPage(browser, email, PASSWORD);
    const draft = await seedDraftDesign(author);
    const token = await agentAccessToken(author);
    const stored = await request.post(
        `/api/v1/bridge/projects/${draft.projectId}/artifacts`,
        {
            headers: { Authorization: `Bearer ${token}` },
            multipart: {
                name: 'Deleted check',
                'paths[0]': 'index.html',
                'files[0]': {
                    name: 'index.html',
                    mimeType: 'text/html',
                    buffer: Buffer.from('<h1>Deleted check</h1>'),
                },
            },
        },
    );
    expect(stored.status()).toBe(201);
    const { artifactId } = await stored.json();

    const watcher = await openWatcher(
        browser,
        email,
        `/projects/${draft.projectId}/artifacts/${artifactId}/full-screen`,
    );
    await expect(watcher.getByText(BAND)).toHaveCount(0);

    await deleteProject(author, draft.projectId);
    await author.context().close();

    await expect(watcher.getByText(BAND)).toBeVisible(LIVE);
    await expect(watcher.locator('[data-live-content]')).toHaveCount(0);
    await watcher.context().close();
});
