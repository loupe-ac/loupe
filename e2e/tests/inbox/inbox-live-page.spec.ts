/**
 * Browser coverage for the live inbox page: an ask that an agent opens shows in
 * the list of an open page without a reload, and an item that closes in another
 * browser keeps a typed note, and the form locks once its ask closes. The hub
 * delivers the signal, so the run needs a hub.
 */

import { test, expect, type APIRequestContext } from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eInboxLivePage1!';

async function setFlag(
    request: APIRequestContext,
    name: string,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

test.setTimeout(60_000);

// The flags are global, so they go back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setFlag(request, 'inbox.enabled', false);
});

test('a new ask appears, and an ask closed elsewhere locks the typed form', async ({
    browser,
    request,
}) => {
    await setFlag(request, 'inbox.enabled', true);
    await setFlag(request, 'live_updates.enabled', true);

    const email = `e2e+inbox+live+page+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Inbox Live Page', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/inbox');
    expect(seeded.status()).toBe(201);
    const first = await seeded.json();

    await watcher.goto(`/projects/${first.projectId}/inbox`);
    await expect(watcher.locator('a.lp-list-row')).toHaveCount(1);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(
        watcher.locator('a[data-controller="inbox-pill"]'),
    ).toHaveAttribute('data-inbox-pill-connected', '');
    await watcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });
    const note = watcher.locator('.lp-decision__note-field').first();
    await note.fill('Use CSV, the importer reads it faster');

    const actor = await signedInPage(browser, email, PASSWORD);
    const second = await (await actor.request.post('/dev/seed/inbox')).json();

    await expect(watcher.locator('a.lp-list-row')).toHaveCount(2, {
        timeout: 15_000,
    });
    await expect(
        watcher.locator(`a.lp-list-row[data-inbox-request="${second.askId}"]`),
    ).toBeVisible();
    await expect(note).toHaveValue('Use CSV, the importer reads it faster');

    await actor.goto(`/projects/${first.projectId}/inbox?ask=${first.askId}`);
    const question = actor.locator(`#inbox-item-${first.questionNumber}`);
    await question.getByText('JSON', { exact: true }).click();
    await question.getByRole('button', { name: 'Send answer' }).click();
    await expect(actor.locator('[data-inbox-status]')).toContainText(
        'still open',
        { timeout: 15_000 },
    );

    // The to-do still blocks the ask, and an answered question stays editable until its ask closes.
    await expect(note).toHaveValue('Use CSV, the importer reads it faster');
    await expect(note).toHaveJSProperty('readOnly', false);

    const todo = actor.locator(`#inbox-item-${first.todoNumber}`);
    await todo.getByRole('button', { name: 'Mark complete' }).click();
    await expect(actor.locator('[data-inbox-status]')).toContainText(
        'is closed',
        { timeout: 15_000 },
    );

    await expect(watcher.locator('[data-inbox-closed-notice]')).toBeVisible({
        timeout: 15_000,
    });
    await expect(note).toHaveValue('Use CSV, the importer reads it faster');
    await expect(note).toHaveJSProperty('readOnly', true);
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await actor.context().close();
    await watcher.context().close();
});
