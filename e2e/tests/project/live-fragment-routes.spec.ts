/**
 * Browser coverage for the live frames that read fragment routes, and for the
 * Git identity switch that answers a stream. Each action must update the page
 * in place. The live test needs the real Mercure hub, so it imports `test`
 * from `@playwright/test`.
 */

import { test, expect, type Page } from '@playwright/test';
import { expectInPlace, signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eFragmentRoutes1!';

test.setTimeout(240_000);

async function register(
    request: import('@playwright/test').APIRequestContext,
    email: string,
): Promise<void> {
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Fragment User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
}

async function seedProject(page: Page): Promise<string> {
    const seeded = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Fragment Project', markdown: '# Fragment' },
    });
    expect(seeded.status()).toBe(201);

    return (await seeded.json()).projectId;
}

test('the Git identity switch updates the panel and flashes in place', async ({
    browser,
    request,
}) => {
    const email = `e2e+identity+${RUN}@example.com`;
    await register(request, email);
    const page = await signedInPage(browser, email, PASSWORD);
    const projectId = await seedProject(page);

    await page.goto(`/projects/${projectId}/connect`);
    const panel = page.locator('#git-identity');
    const toggle = panel.getByLabel('Loupe manages the git identity of agents');
    await expect(toggle).toBeChecked();

    await expectInPlace(page, async () => {
        // The submit-on-change controller loads lazily, so a change that
        // lands before it connects submits nothing. Send it again until it does.
        await expect(async () => {
            const sent = page.waitForRequest(
                (request) =>
                    request.method() === 'POST' &&
                    request.url().endsWith('/git-identity'),
                { timeout: 3_000 },
            );
            await toggle.evaluate((input: HTMLInputElement) => {
                input.checked = false;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            await sent;
        }).toPass({ timeout: 30_000 });
        await expect(page.locator('.lp-flash--success')).toContainText(
            'Agents now use the git setup of each machine.',
            { timeout: 60_000 },
        );
    });
    await expect(
        page
            .locator('#git-identity')
            .getByLabel('Loupe manages the git identity of agents'),
    ).not.toBeChecked();

    await page.context().close();
});

test('a column change reaches the settings and workflow pages through their fragment routes', async ({
    browser,
    request,
}) => {
    const email = `e2e+fragments+${RUN}@example.com`;
    await register(request, email);
    const editor = await signedInPage(browser, email, PASSWORD);
    const projectId = await seedProject(editor);

    const columns = await editor.context().newPage();
    const workflow = await editor.context().newPage();
    for (const [watcher, path] of [
        [columns, `/projects/${projectId}/settings/columns`],
        [workflow, `/projects/${projectId}/workflow`],
    ] as const) {
        await watcher.goto(path);
        await expect(
            watcher.locator('[data-controller~="live-region"]'),
        ).toHaveCount(1);
        await watcher.evaluate(() => {
            (window as unknown as { stayed: boolean }).stayed = true;
        });
    }
    // The hub keeps no history, so a change made before the pages connect is lost.
    await columns.waitForTimeout(1500);

    const columnsFrame = columns.waitForResponse(
        (response) =>
            response
                .url()
                .endsWith(`/projects/${projectId}/settings/columns/frame`) &&
            response.status() === 200,
        { timeout: 60_000 },
    );
    const workflowFrame = workflow.waitForResponse(
        (response) =>
            response.url().endsWith(`/projects/${projectId}/workflow/frame`) &&
            response.status() === 200,
        { timeout: 60_000 },
    );

    await editor.goto(`/projects/${projectId}/settings/columns`);
    const settings = editor.locator('[data-board-column-settings]');
    await settings
        .getByRole('button', { name: 'Add a column', exact: true })
        .click();
    const dialog = editor.getByRole('dialog');
    await dialog.evaluate((element) =>
        Promise.all(
            element
                .getAnimations()
                .map((animation) => animation.finished.catch(() => {})),
        ),
    );
    await dialog.getByRole('textbox').fill('Fragment lane');
    await dialog
        .getByRole('button', { name: 'Add column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: 'Fragment lane', exact: true }),
    ).toBeVisible({ timeout: 60_000 });

    await columnsFrame;
    await workflowFrame;
    await expect(
        columns
            .locator('[data-board-column-settings]')
            .getByRole('heading', { name: 'Fragment lane', exact: true }),
    ).toBeVisible({ timeout: 60_000 });
    for (const watcher of [columns, workflow]) {
        expect(
            await watcher.evaluate(
                () => (window as unknown as { stayed?: boolean }).stayed,
            ),
            'the live update replaced the page',
        ).toBe(true);
    }

    await editor.context().close();
});
