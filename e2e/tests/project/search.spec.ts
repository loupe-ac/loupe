import { expect, type Route } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-project-search@example.com',
    password: 'e2e_password_123',
});

test.describe.configure({ mode: 'default' });

const FIELD = {
    name: 'Search pages, cards, and documents',
    exact: true,
} as const;

for (const entry of ['/account', '/projects']) {
    test(`search from ${entry} finds owned-project documents`, async ({
        page,
    }) => {
        const query = `beryl${Date.now()}`;
        const response = await page.request.post('/dev/seed/document', {
            form: { title: query, markdown: query },
        });
        expect(response.ok()).toBeTruthy();
        const { projectId, documentId } = await response.json();
        await page.goto(entry);
        await page.getByRole('combobox', FIELD).fill(query);
        const result = page.locator('[data-search-kind="document"]');
        await expect(result).toBeVisible();
        await expect(result).toHaveCount(1);
        await expect(result).toContainText(query);
        await expect(page.locator('[data-search-all]')).toHaveAccessibleName(
            `All results for “${query}”`,
        );
        await expect(result).toHaveAttribute(
            'href',
            `/projects/${projectId}/documents/${documentId}/review`,
        );
        await result.click();
        await expect(
            page.getByRole('heading', { name: query, exact: true }),
        ).toBeVisible();
    });
}

test('project search finds documents and pages from the top bar', async ({
    page,
}) => {
    const query = `quartz${Date.now()}`;
    const title = `Searchable document ${query}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId, documentId } = await response.json();
    await page.goto(`/projects/${projectId}`);
    const field = page.getByRole('combobox', FIELD);

    await field.fill(query.slice(2, -2));
    const result = page.locator('[data-search-kind="document"]');
    await expect(result).toBeVisible();
    await expect(result).toHaveCount(1);
    await expect(result).toContainText(title);
    await expect(
        page.getByRole('link', { name: /^All results for/ }),
    ).toBeVisible();
    await expect(page.getByText('Could not search')).toBeHidden();
    await field.press('ArrowDown');
    await expect(result).toHaveAttribute('aria-selected', 'true');
    await expect(field).toBeFocused();
    await field.press('Enter');
    await expect(page).toHaveURL(
        new RegExp(`/documents/${documentId}/review$`),
    );

    await page.goto(`/projects/${projectId}`);
    await field.fill('Activity');
    const pages = page.locator('[data-search-kind="page"]');
    await expect(pages).toHaveCount(2);
    await expect(pages).toContainText(['Activity · Runs', 'Activity · Events']);
    await pages.filter({ hasText: 'Activity · Events' }).click();
    await expect(page).toHaveURL(
        new RegExp(`/projects/${projectId}/activity$`),
    );

    await field.fill(`missing${query}`);
    await expect(page.getByRole('status')).toContainText('No results for');
    await field.press('Enter');
    await expect(page).toHaveURL(
        new RegExp(`/projects/${projectId}/search\\?query=missing`),
    );
    await expect(page.getByRole('status')).toHaveText(
        'No matching pages, cards, or documents.',
    );
});

test('the top bar search is an icon on a phone and opens over the bar', async ({
    page,
}) => {
    const query = `onyx${Date.now()}`;
    const title = `Phone ${'wide '.repeat(12)}${query}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId } = await response.json();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/projects/${projectId}`);
    const field = page.getByRole('combobox', FIELD);
    await expect(field).toBeHidden();
    await page
        .getByRole('button', { name: 'Search project', exact: true })
        .click();
    await expect(field).toBeFocused();
    await field.fill(query);
    const result = page.locator('[data-search-kind="document"]');
    await expect(result).toBeVisible();
    await expect(page.locator('[data-search-all]')).toHaveAccessibleName(
        'All results',
    );
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(390);
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(field).toBeHidden();
});

test('search shortcuts preserve page drafts and recover from a failed request', async ({
    page,
}) => {
    const query = `sapphire${Date.now()}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title: query, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId, documentId } = await response.json();
    const editUrl = `/projects/${projectId}/edit`;
    await page.goto(editUrl);
    const name = page.getByLabel('Project name', { exact: true });
    await name.fill('Unsaved project draft');
    const field = page.getByRole('combobox', FIELD);
    await page.keyboard.press('Control+k');
    await expect(field).toBeFocused();
    await field.fill(query);
    await expect(page.locator('[data-search-kind="document"]')).toHaveCount(1);
    await expect(page).toHaveURL(new RegExp(`${editUrl}$`));
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-search-kind="document"]')).toBeHidden();
    await expect(name).toHaveValue('Unsaved project draft');
    await field.fill('');

    const searchPattern = new RegExp(`/projects/${projectId}/search(?:\\?|$)`);
    let pendingRequest: (route: Route) => void;
    const requested = new Promise<Route>((resolve) => {
        pendingRequest = resolve;
    });
    await page.route(searchPattern, (route) => pendingRequest(route));
    await page.keyboard.press('Meta+k');
    await expect(field).toBeFocused();
    await field.fill(query);
    const failedRequest = await requested;
    await expect(
        page.locator('[data-topbar-search-target="progress"]'),
    ).toBeVisible();
    await expect(page.getByRole('status')).toBeHidden();
    await failedRequest.fulfill({ status: 503, body: 'Unavailable' });
    await expect(page.getByRole('alert')).toContainText('Could not search');
    await expect(field).toHaveValue(query);
    await page.unroute(searchPattern);
    await page.getByRole('button', { name: 'Try again', exact: true }).click();
    const result = page.locator('[data-search-kind="document"]');
    await expect(result).toBeVisible();
    await expect(page.getByText('Could not search')).toBeHidden();
    await field.press('ArrowDown');
    await expect(result).toHaveAttribute('aria-selected', 'true');
    await expect(field).toBeFocused();
    await field.press('Enter');
    await expect(page).toHaveURL(
        new RegExp(`/documents/${documentId}/review$`),
    );
    await expect(
        page.getByRole('heading', { name: query, exact: true }),
    ).toBeVisible();
});

test('search keeps a working full-page fallback without JavaScript', async ({
    page,
    browser,
    context,
}) => {
    const query = `topaz${Date.now()}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title: query, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId } = await response.json();
    const fallbackContext = await browser.newContext({
        storageState: await context.storageState(),
        ignoreHTTPSErrors: true,
        javaScriptEnabled: false,
    });
    try {
        const fallback = await fallbackContext.newPage();
        await fallback.goto(
            `${new URL(response.url()).origin}/projects/${projectId}`,
        );
        await fallback.getByRole('combobox', FIELD).fill(query);
        await fallback.getByRole('combobox', FIELD).press('Enter');
        await expect(
            fallback.locator('[data-search-kind="document"]'),
        ).toContainText(query);
        await expect(
            fallback.getByRole('heading', {
                name: 'Search project',
                exact: true,
            }),
        ).toBeVisible();
    } finally {
        await fallbackContext.close();
    }
});
