import { expect } from '@playwright/test';
import {
    agentAccessToken,
    createTest,
    mcpCards,
    suppressWidget,
} from '../fixtures';

const test = createTest({
    email: `e2e-bridge-names-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

test('a bridge shows its name, and a second claim of the name shows a clash', async ({
    page,
}) => {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Bridge names', markdown: '# Names' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const token = await agentAccessToken(page);
    const card = await (await mcpCards(page, projectId))('Bridge names card');
    const headers = { Authorization: `Bearer ${token}` };
    const heartbeat = async (bridgeId: string, name?: string) => {
        const response = await page.request.put(
            `/api/v1/bridge/bridges/${bridgeId}/heartbeat`,
            {
                headers,
                data: {
                    projects: [projectId],
                    cliVersion: '1.0.0',
                    ...(name === undefined ? {} : { name }),
                },
            },
        );
        expect(response.status()).toBe(200);
    };
    const report = async (bridgeId: string) => {
        const response = await page.request.put(
            `/api/v1/bridge/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
            {
                headers,
                data: {
                    bridgeId,
                    at: '2026-10-02T10:00:00+00:00',
                    workKind: 'implement',
                    subjectType: 'card',
                    subjectId: card.id,
                    cardNumber: card.number,
                    state: 'queued',
                },
            },
        );
        expect(response.status()).toBe(201);
    };

    const holder = crypto.randomUUID();
    const claimer = crypto.randomUUID();
    const unnamed = crypto.randomUUID();
    await heartbeat(holder, 'homelab');
    await heartbeat(claimer, 'homelab');
    await heartbeat(unnamed);

    await page.goto(`/projects/${projectId}/agents`);
    const agentCard = (bridgeId: string) =>
        page.locator(`[data-agent-connection-id="${bridgeId}"]`);
    await expect(agentCard(holder).locator('.lp-agent-card__name')).toHaveText(
        'homelab',
    );
    await expect(
        agentCard(holder).locator('[data-agent-name-clash]'),
    ).toHaveCount(0);
    await expect(agentCard(claimer).locator('.lp-agent-card__name')).toHaveText(
        claimer.slice(-12),
    );
    const clash = agentCard(claimer).locator('[data-agent-name-clash]');
    await expect(clash).toBeVisible();
    await expect(clash).toContainText('Name already in use');
    await expect(clash).toContainText('homelab');
    await expect(agentCard(unnamed).locator('.lp-agent-card__name')).toHaveText(
        unnamed.slice(-12),
    );
    await expect(
        agentCard(unnamed).locator('[data-agent-name-clash]'),
    ).toHaveCount(0);

    await report(holder);
    await report(unnamed);
    await page.goto(`/projects/${projectId}/worker-runs`);
    const filter = page.locator('#worker-run-bridge');
    await expect(filter.locator(`option[value="${holder}"]`)).toHaveText(
        'homelab',
    );
    await expect(filter.locator(`option[value="${unnamed}"]`)).toHaveText(
        unnamed.slice(-12),
    );
});
