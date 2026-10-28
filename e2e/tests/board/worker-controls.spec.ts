/**
 * The run and agent controls on the card page and the Runs list. The heartbeat and the run go
 * through the real bridge endpoints, so no bridge runs and no request settles.
 */

import { expect, type Page } from '@playwright/test';
import { agentAccessToken, createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-worker-controls-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

test('the owner stops a run, which holds nothing, then pauses and releases the agents', async ({
    page,
}) => {
    // A card, a board load, a token and two reports outlast the default budget on CI.
    test.slow();
    await suppressWidget(page);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Worker Controls', markdown: '# Controls' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Alpha');
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: 'Alpha' })).toBeVisible({
        timeout: 15_000,
    });
    await page.goto(`/projects/${projectId}/board`);
    const cardId = await page
        .locator('article[data-card-title="Alpha"]')
        .getAttribute('data-card-id');
    expect(cardId).not.toBeNull();

    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const heartbeat = await page.request.put(
        `/api/bridges/${bridgeId}/heartbeat`,
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
    const report = await page.request.put(
        `/api/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                bridgeId,
                state: 'running',
                at: new Date().toISOString(),
                subjectType: 'card',
                subjectId: cardId,
                cardNumber: 1,
                workKind: 'implement',
                sessionId: crypto.randomUUID(),
                startedAt: new Date().toISOString(),
            },
        },
    );
    expect(report.status()).toBe(201);
    const runId = (await report.json()).id as string;

    await page.goto(`/projects/${projectId}/board/cards/${cardId}`);
    const row = page.locator(`[data-card-run-row="${runId}"]`);
    const label = row.locator('[data-worker-run-control-label]');
    const held = page.locator('[data-card-held]');
    const stop = row.getByRole('button', { name: 'Stop', exact: true });
    await expect(stop).toBeEnabled();
    await expect(held).toHaveCount(0);

    await stop.click();
    await expect(label).toHaveText('Stop requested');
    await expect(held).toHaveCount(0);

    await row
        .getByRole('button', { name: 'Cancel request', exact: true })
        .click();
    await expect(stop).toBeEnabled();
    await expect(label).toHaveCount(0);
    await expect(held).toHaveCount(0);

    const runs = page.locator('turbo-frame#card-worker-runs');
    await runs
        .getByRole('button', { name: 'Make unmanaged', exact: true })
        .click();
    await expect(held).toBeVisible();
    await expect(held).toContainText(
        'Unmanaged: the workflow makes no move and starts no work on this card.',
    );
    const release = runs.getByRole('button', {
        name: 'Manage again',
        exact: true,
    });
    await expect(release).toBeVisible();
    await expect(
        runs.getByRole('button', { name: 'Make unmanaged', exact: true }),
    ).toHaveCount(0);

    await release.click();
    await expect(held).toHaveCount(0);
    await expect(
        runs.getByRole('button', { name: 'Make unmanaged', exact: true }),
    ).toBeVisible();
});

/** Calls one MCP tool with the signed-in user's agent token, and returns its structured answer. */
async function mcpTools(
    page: Page,
): Promise<(name: string, args: object) => Promise<Record<string, unknown>>> {
    const token = await agentAccessToken(page);
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
    return async (name, args) => {
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
}

test('the owner closes an interactive session from the Runs list, and the Open runs view drops it', async ({
    page,
}) => {
    test.slow();
    await suppressWidget(page);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Close Sessions', markdown: '# Close' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    const call = await mcpTools(page);
    const card = await call('card_create', {
        title: 'Session card',
        body: '',
        type: 'feature',
        status: 'next',
    });
    const openRun = async (): Promise<string> => {
        const answer = await call('card_run_open', {
            cardId: card.cardId,
            sessionId: crypto.randomUUID(),
            name: 'loupe:product-design',
        });
        return (answer.run as { runId: string }).runId;
    };
    const first = await openRun();
    const second = await openRun();

    await page.goto(`/projects/${projectId}/worker-runs?outcome=`);
    const firstRow = page.locator(`[data-worker-run-id="${first}"]`);
    const firstClose = firstRow.locator(
        '.lp-worker-run-outcome form[data-worker-run-control="close"] button',
    );
    await expect(firstRow.locator('.lp-worker-run-outcome')).toContainText(
        'Running',
    );
    await firstClose.click();
    await expect(firstRow.locator('.lp-worker-run-outcome')).toContainText(
        'Closed',
    );
    await expect(firstClose).toHaveCount(0);

    await page.goto(`/projects/${projectId}/worker-runs?outcome=open`);
    const secondRow = page.locator(`[data-worker-run-id="${second}"]`);
    await expect(page.locator(`[data-worker-run-id="${first}"]`)).toHaveCount(
        0,
    );
    await secondRow
        .locator(
            '.lp-worker-run-outcome form[data-worker-run-control="close"] button',
        )
        .click();
    await expect(secondRow).toHaveCount(0);
    await expect(page).toHaveURL(/outcome=open/);
});
