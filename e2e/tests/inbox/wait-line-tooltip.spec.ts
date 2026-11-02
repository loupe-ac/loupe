/**
 * The wait line of a review page on a phone with a touch screen. Its tooltip
 * starts near the right edge of the byline, so the page scrolls sideways when
 * the hidden tooltip stays in the layout.
 */

import { expect, type APIRequestContext } from '@playwright/test';
import { createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-wait-line-tooltip-${crypto.randomUUID()}@example.com`,
    password: 'e2e_password_123',
});

async function setInbox(
    request: APIRequestContext,
    enabled: 0 | 1,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled },
    });
    expect(response.ok()).toBeTruthy();
}

test.use({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

// The flag is global, so it goes back to its shipped value for later specs.
test.afterAll(async ({ request }) => {
    await setInbox(request, 0);
});

test('the wait line tooltip adds no sideways scroll on a touch screen', async ({
    page,
}) => {
    await suppressWidget(page);
    await setInbox(page.request, 1);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Plain tooltip', markdown: '# Plain tooltip' },
    });
    expect(seed.status()).toBe(201);
    const { projectId, documentId } = await seed.json();
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Waiting card');
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(
        page.getByRole('heading', { name: 'Waiting card' }),
    ).toBeVisible();
    const cardId = new URL(page.url()).pathname.split('/').pop() ?? '';
    const review = await page.request.post('/dev/seed/inbox-review', {
        form: { cardId, documentId },
    });
    expect(review.status()).toBe(201);

    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);
    await expect(page.locator('#document-wait-line')).toBeVisible();
    await expect(page.locator('#document-wait-line .lp-tooltip')).toHaveCSS(
        'display',
        'none',
    );
    const widths = await page
        .locator('.lp-main')
        .evaluate((main) => [main.scrollWidth, main.clientWidth]);
    expect(widths[0]).toBe(widths[1]);
});
