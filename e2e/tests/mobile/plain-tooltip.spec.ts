/**
 * A plain tooltip on a phone with a touch screen. `hasTouch` makes
 * `(hover: none)` match in Chromium, and a hidden tooltip that stays in the
 * layout would add sideways scroll.
 */

import { expect } from '@playwright/test';
import { agentAccessToken, createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-plain-tooltip-${crypto.randomUUID()}@example.com`,
    password: 'e2e_password_123',
});

test.use({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

test('a touch screen hides the tooltip and the help icon of a chip', async ({
    page,
}) => {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Plain tooltip', markdown: '# Plain tooltip' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const token = await agentAccessToken(page);
    const runId = crypto.randomUUID();
    const response = await page.request.put(
        `/api/projects/${projectId}/worker-runs/${runId}`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                bridgeId: crypto.randomUUID(),
                workKind: 'implement',
                sessionId: crypto.randomUUID(),
                subjectType: 'card',
                subjectId: crypto.randomUUID(),
                cardNumber: 1,
                startedAt: '2026-09-17T12:00:00+00:00',
                state: 'not-started',
                at: '2026-09-17T12:00:21+00:00',
                endedAt: '2026-09-17T12:00:21+00:00',
                exitCode: null,
                hasResult: null,
                failureReason: 'Worker executable unavailable',
                output: 'none',
            },
        },
    );
    expect(response.status()).toBe(201);
    const { id } = await response.json();

    await page.goto(`/projects/${projectId}/worker-runs`);
    const chip = page.locator(
        `[data-worker-run-id="${id}"] > .lp-worker-run-outcome > .lp-worker-run-outcome__status > .lp-status-chip`,
    );
    await expect(chip).toBeVisible();
    await expect(chip.locator('.lp-tooltip')).toHaveCSS('display', 'none');
    await expect(chip.locator('.lp-status-chip__help')).toHaveCSS(
        'display',
        'none',
    );

    await page.evaluate(() => {
        const sign = document.createElement('span');
        sign.id = 'sign-probe';
        sign.className = 'lp-tooltip lp-chart__sign-detail';
        document.body.appendChild(sign);
    });
    const probe = page.locator('#sign-probe');
    await expect(probe).toHaveCSS('position', 'fixed');
    await expect(probe).not.toHaveCSS('display', 'none');
});
