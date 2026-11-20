/**
 * The Runs list updates in place. A close from the run drawer answers a stream,
 * so the filter stays and the page is not replaced.
 */

import { test, expect } from '@playwright/test';
import {
    accessToken,
    agentAccessToken,
    expectInPlace,
    signedInPage,
} from '../fixtures';

const PASSWORD = 'E2eRunsInPlace1!';

test.setTimeout(240_000);

test('a close from the run drawer updates the list in place and keeps the filter', async ({
    browser,
    request,
}) => {
    const email = `e2e+runs-in-place+${Date.now()}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Runs User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
    const page = await signedInPage(browser, email, PASSWORD);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Drawer Close', markdown: '# Drawer' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    const token = await accessToken(page, 'agent mcp', projectId);
    const headers: Record<string, string> = {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
    };
    const initialize = await page.request.post('/mcp', {
        headers,
        data: {
            jsonrpc: '2.0',
            id: 1,
            method: 'initialize',
            params: {
                protocolVersion: '2024-11-05',
                capabilities: {},
                clientInfo: { name: 'e2e', version: '1' },
            },
        },
    });
    expect(initialize.ok()).toBeTruthy();
    headers['Mcp-Session-Id'] = initialize.headers()['mcp-session-id'] ?? '';
    await page.request.post('/mcp', {
        headers,
        data: { jsonrpc: '2.0', method: 'notifications/initialized' },
    });
    let id = 1;
    const call = async (
        name: string,
        args: object,
    ): Promise<Record<string, any>> => {
        id += 1;
        const response = await page.request.post('/mcp', {
            headers,
            data: {
                jsonrpc: '2.0',
                id,
                method: 'tools/call',
                params: { name, arguments: args },
            },
        });
        const answer = await response.json();
        expect(answer.result?.isError, JSON.stringify(answer)).toBeFalsy();

        return answer.result.structuredContent;
    };

    const card = await call('card_create', {
        title: 'Drawer card',
        body: '',
        type: 'feature',
        status: 'next',
    });
    const openRun = async (): Promise<string> =>
        (
            await call('card_run_open', {
                cardId: card.cardId,
                sessionId: crypto.randomUUID(),
                name: 'loupe-lifecycle:product-design',
            })
        ).run.runId;
    const first = await openRun();
    const second = await openRun();

    await page.goto(`/projects/${projectId}/worker-runs?outcome=open`);
    const firstRow = page.locator(`[data-worker-run-id="${first}"]`);
    const secondRow = page.locator(`[data-worker-run-id="${second}"]`);
    await expect(secondRow).toBeVisible();

    await firstRow.locator('button[data-action="modal#open"]').click();
    const drawer = firstRow.locator('dialog[open]');
    await expect(drawer).toBeVisible();

    await expectInPlace(page, async () => {
        await drawer
            .locator('form[data-worker-run-control="close"] button')
            .click();
        await expect(firstRow).toHaveCount(0, { timeout: 30_000 });
    });
    await expect(secondRow).toBeVisible();
    await expect(page).toHaveURL(/outcome=open/);
    await expect(page.locator('#worker-run-outcome')).toHaveValue('open');

    await page.context().close();
});

test('pausing an agent updates its card in place', async ({
    browser,
    request,
}) => {
    const email = `e2e+agents-in-place+${Date.now()}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Agents User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
    const page = await signedInPage(browser, email, PASSWORD);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Agents Pause', markdown: '# Agents' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const heartbeat = await page.request.put(
        `/api/v1/bridge/bridges/${bridgeId}/heartbeat`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                projects: [projectId],
                cliVersion: '1.5.0',
                capabilities: ['commands'],
            },
        },
    );
    expect(heartbeat.status()).toBe(200);

    await page.goto(`/projects/${projectId}/agents`);
    const card = page.locator(`#agent-connection-${bridgeId}`);
    await expect(card).toBeVisible();

    await expectInPlace(page, async () => {
        await card.locator('[data-popover-target="trigger"]').click();
        await card
            .locator('form[data-agent-pause-control="pause"] button')
            .click();
        await expect(card.locator('[data-agent-paused]')).toBeVisible({
            timeout: 30_000,
        });
    });
    await expect(page).toHaveURL(/\/agents$/);

    await page.context().close();
});
