/**
 * The touch rule at a 390px phone with a coarse pointer.
 *
 * iOS Safari zooms the page in when it focuses a text field under 16px, and a
 * `display` in the touch rule must not beat `hidden` or `lg:hidden`.
 */

import { expect, type Page } from '@playwright/test';
import {
    hubStubbedTest as test,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

test.use({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

async function fontSizeOf(page: Page, selector: string): Promise<string> {
    const field = page.locator(selector).first();
    await expect(field, selector).toBeAttached();
    return field.evaluate((element) => getComputedStyle(element).fontSize);
}

test('a sign-in field has 16px text', async ({ page }) => {
    await page.goto('/login');
    expect(await fontSizeOf(page, '.lp-input')).toBe('16px');
});

test('the Remember me label is a 24px target', async ({ page }) => {
    await page.goto('/login');
    const box = await page.locator('.lp-auth__remember').boundingBox();
    expect(box?.height).toBeGreaterThanOrEqual(24);
});

test('a select and a text area of the design system have 16px text', async ({
    page,
}) => {
    await page.goto('/styleguide');
    expect(await fontSizeOf(page, 'select.lp-select')).toBe('16px');
    expect(await fontSizeOf(page, 'textarea.lp-textarea')).toBe('16px');
});

test('the backlog filter field and the board Find field have 16px text, and a Backlog check box is 24px', async ({
    page,
}) => {
    test.slow();
    const email = `e2e+touch+${Date.now()}@example.com`;
    const password = 'E2eTouchRule1!';
    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Touch', email, password },
    });
    expect(registered.status()).toBe(200);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 20_000 });

    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'Touch rule', markdown: '# Touch rule' },
    });
    expect(response.status()).toBe(201);
    const { projectId } = await response.json();

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Backlog card');
    await page.getByLabel('Column').selectOption({ label: 'Backlog' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(
        page.getByRole('heading', { name: 'Backlog card' }),
    ).toBeVisible({ timeout: 15_000 });

    await page.goto(`/projects/${projectId}/board/backlog`);
    expect(await fontSizeOf(page, '.lp-list-filters .lp-input')).toBe('16px');
    const check = await page
        .locator('.lp-backlog-check.lp-touch-check')
        .first()
        .boundingBox();
    expect(check?.height).toBeGreaterThanOrEqual(24);
    expect(check?.width).toBeGreaterThanOrEqual(24);
});

test('a utility beats the touch rule on display', async ({ page }) => {
    await page.goto('/styleguide');
    await page.evaluate(() => {
        const button = document.createElement('button');
        button.id = 'touch-probe';
        button.className = 'lp-btn lg:hidden hidden';
        document.body.appendChild(button);
    });
    await expect(page.locator('#touch-probe')).toBeHidden();
});
