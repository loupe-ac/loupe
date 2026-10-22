import { expect, type Page } from '@playwright/test';
import { accessToken } from '../fixtures';

export interface DraftDesign {
    projectId: string;
    documentId: string;
    cardId: string;
    reviewUrl: string;
}

export interface DraftDesignOptions {
    /** Failed agent reviews to store, so the band reads round failed + 1. */
    failedRounds?: number;
    /** Pauses the card, so the band takes its paused form. */
    paused?: boolean;
    /** Links no card, so the band takes its plain form. */
    linkCard?: boolean;
}

const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

/** Opens an MCP session with a token bound to the project, and returns a tool call. */
async function mcpTools(
    page: Page,
    token: string,
): Promise<
    (
        name: string,
        args: Record<string, unknown>,
    ) => Promise<Record<string, any>>
> {
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
    expect(headers['Mcp-Session-Id']).not.toBe('');
    await page.request.post('/mcp', {
        headers,
        data: { jsonrpc: '2.0', method: 'notifications/initialized' },
    });

    let id = 2;

    return async (name, args) => {
        const response = await page.request.post('/mcp', {
            headers,
            data: {
                jsonrpc: '2.0',
                id: id++,
                method: 'tools/call',
                params: { name, arguments: args },
            },
        });
        const answer = await response.json();
        expect(answer.result?.isError, JSON.stringify(answer)).toBeFalsy();

        return answer.result.structuredContent;
    };
}

/**
 * A project with the default workflow, a tech design draft, and a card in
 * Tech design that the draft is linked to. `page` must be signed in.
 */
export async function seedDraftDesign(
    page: Page,
    options: DraftDesignOptions = {},
): Promise<DraftDesign> {
    await page.goto('/projects');
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i })
        .click();
    await page
        .getByLabel('Project name')
        .fill(`draft-band-${crypto.randomUUID().slice(0, 8)}`);
    await page.getByRole('button', { name: 'Add project' }).click();
    await expect(page.locator('[data-workshop]')).toBeVisible(ROUND_TRIP);
    const projectId = /\/projects\/([0-9a-f-]{36})/.exec(page.url())?.[1];
    expect(projectId).toBeTruthy();

    const call = await mcpTools(
        page,
        await accessToken(page, 'mcp', projectId!),
    );
    const document = await call('document_create', {
        title: 'Tech design: the draft band',
        markdown: '# Draft band\n\nA design that waits for its review.',
        tags: ['tech-design'],
        draft: true,
    });
    const documentId = document.documentId as string;

    let cardId = '';
    if (options.linkCard !== false) {
        const card = await call('card_create', {
            title: 'Draft band card',
            body: '',
            type: 'feature',
            status: 'tech-design',
            documentIds: [documentId],
        });
        cardId = card.cardId as string;

        for (let round = 0; round < (options.failedRounds ?? 0); round++) {
            const seeded = await page.request.post(
                `/dev/seed/document-agent-review/${projectId}/${documentId}`,
            );
            expect(seeded.status()).toBe(201);
        }
        if (options.paused) {
            const paused = await page.request.post(
                `/dev/seed/workflow-pause/${projectId}/${cardId}`,
                { form: { rule: 'tech-design-review' } },
            );
            expect(paused.status()).toBe(201);
        }
    }

    return {
        projectId: projectId!,
        documentId,
        cardId,
        reviewUrl: `/projects/${projectId}/documents/${documentId}/review`,
    };
}
