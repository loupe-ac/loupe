import { expect, type Page } from '@playwright/test';
import { createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-metrics-formula-${crypto.randomUUID()}@example.com`,
    password: 'e2e_password_123',
});

async function openMetrics(page: Page): Promise<void> {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Metrics formula', markdown: '# Formula' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    await page.goto(`/projects/${projectId}/analytics/metrics`);
    await expect(
        page.getByRole('button', { name: 'Add formula' }),
    ).toBeVisible();
}

async function chooseMetric(
    page: Page,
    row: string,
    search: string,
    label: string,
): Promise<void> {
    const field = page.locator(`[data-letter="${row}"] .ts-control`);
    await field.click();
    await field.locator('input').fill(search);
    await page.getByRole('option', { name: label, exact: true }).click();
}

test('a formula builds in the page and closes onto the earliest row', async ({
    page,
}) => {
    await openMetrics(page);

    await page.getByRole('button', { name: 'Add formula' }).click();
    await expect(page.locator('[data-letter]')).toHaveCount(2);
    await expect(page.getByLabel('Formula', { exact: true })).toHaveValue(
        'A / B',
    );
    await expect(
        page.getByRole('button', { name: 'Add formula' }),
    ).toBeHidden();

    await chooseMetric(page, 'A', 'cache read', 'Cache read tokens');
    await chooseMetric(page, 'B', 'cache write', 'Cache write tokens');
    await expect(page).toHaveURL(/rows=A%3Acache-read-tokens%3Asum/);
    await expect(page).toHaveURL(/formula=A\+%2F\+B/);
    await expect(page.getByLabel('Formula', { exact: true })).toHaveValue(
        'A / B',
    );
    await expect(page.locator('[data-letter]')).toHaveCount(2);

    await page.locator('[data-letter="A"] button').click();
    await page.getByRole('button', { name: 'Remove formula' }).click();
    await expect(page).not.toHaveURL(/rows=/);
    await expect(page).toHaveURL(/metric=cache-write-tokens/);
    await expect(
        page.getByRole('button', { name: 'Add formula' }),
    ).toBeVisible();
});

test('a broken formula shows its error, keeps the page and blocks the submit', async ({
    page,
}) => {
    await openMetrics(page);
    await page.getByRole('button', { name: 'Add formula' }).click();
    await chooseMetric(page, 'A', 'cache read', 'Cache read tokens');
    await chooseMetric(page, 'B', 'cache write', 'Cache write tokens');
    await expect(page).toHaveURL(/rows=/);

    const formula = page.getByLabel('Formula', { exact: true });
    await formula.fill('A / (B');
    await expect(page.getByRole('alert')).toContainText('parenthesis');
    await expect(page.locator('[data-metrics-form]')).toHaveAttribute(
        'data-formula-invalid',
        '',
    );
    await expect(page).toHaveURL(/formula=A\+%2F\+B/);
});

test('removing a row keeps the other letters and the formula names the missing one', async ({
    page,
}) => {
    await openMetrics(page);
    await page.getByRole('button', { name: 'Add formula' }).click();
    await page.getByRole('button', { name: 'Add metric' }).click();
    await expect(page.locator('[data-letter]')).toHaveCount(3);

    await page.locator('[data-letter="B"] button').click();
    await expect(page.locator('[data-letter]')).toHaveCount(2);
    await expect(page.locator('[data-letter="C"]')).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('B');
});
