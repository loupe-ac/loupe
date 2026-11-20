/**
 * The artifacts list filters inside its frame, and a live signal that another
 * actor sends keeps the archived filter. The spec turns the artifacts flag on,
 * so it runs in the global-flags project.
 */

import { test, expect } from '@playwright/test';
import {
    agentAccessToken,
    signedInPage,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eArtifactFrame1!';
const LIVE = { timeout: coverageScaled(20_000) };

test.setTimeout(coverageScaled(180_000));

test.afterAll(async ({ request }) => {
    await request.post('/dev/e2e/feature-flag', {
        form: { name: 'artifacts.enabled', enabled: 0 },
    });
});

test('an artifacts archived filter survives a live signal from another actor', async ({
    browser,
    request,
}) => {
    const email = `e2e+artifactframe+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E List Frames', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
    const flag = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'artifacts.enabled', enabled: 1 },
    });
    expect(flag.ok()).toBeTruthy();

    const watcher = await signedInPage(browser, email, PASSWORD);
    const seeded = await watcher.request.post('/dev/seed/document', {
        form: { title: 'E2E Artifacts Frames', markdown: '# Artifacts' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = ((await seeded.json()) as { projectId: string })
        .projectId;
    const actor = await signedInPage(browser, email, PASSWORD);
    const token = await agentAccessToken(actor);
    const store = async (name: string): Promise<void> => {
        const stored = await actor.request.post(
            `/api/v1/bridge/projects/${projectId}/artifacts`,
            {
                headers: { Authorization: `Bearer ${token}` },
                multipart: {
                    name,
                    'paths[0]': 'index.html',
                    'files[0]': {
                        name: 'index.html',
                        mimeType: 'text/html',
                        buffer: Buffer.from(`<h1>${name}</h1>`),
                    },
                },
            },
        );
        expect(stored.status()).toBe(201);
    };
    await store('First artifact');
    await store('Kept artifact');

    await suppressToolbar(watcher);
    await suppressWidget(watcher);
    await watcher.goto(`/projects/${projectId}/artifacts`);
    await watcher
        .getByRole('button', { name: 'Archive First artifact' })
        .click();
    await expect(watcher.locator('[data-artifact-id]')).toHaveCount(1, LIVE);
    const toggle = watcher.locator('[data-artifact-archived-toggle]');
    await expect(toggle).toBeVisible();
    // The switch sits outside the swapped frame, so a swap keeps the same element.
    await toggle.evaluate((element) => element.setAttribute('data-kept', ''));
    await toggle.click();
    await expect(watcher).toHaveURL(/archived=1/, LIVE);
    await expect(toggle).toHaveAttribute('data-kept', '');
    await expect(watcher.locator('[data-artifact-id]')).toHaveCount(2);

    // The hub keeps no history, so a change made before the page connects is lost.
    await watcher.waitForTimeout(1_000);
    await store('Second artifact');

    await expect(watcher.locator('[data-artifact-id]')).toHaveCount(3, LIVE);
    await expect(watcher.locator('[data-artifact-archived]')).toHaveCount(1);
    await expect(watcher).toHaveURL(/archived=1/);
});
