/**
 * The scroll cues of tables and setup commands at a 390px phone.
 *
 * A table that scrolls sideways draws a "›" over the hidden end of its header
 * and a "‹" over the hidden start. A setup command has no arrows, and its
 * right edge fades while more text lies past it.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { submitRedirectingForm } from '../helpers';

const RUN = Date.now();
const PASSWORD = 'E2eScrollCues1!';

type Seeded = { projectId: string; documentId: string };

const test = base.extend<{ signedIn: void; seeded: Seeded }>({
    signedIn: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+scrollcues+${tag}+${RUN}@example.com`;

            const registered = await page.request.post(
                '/dev/register-and-verify',
                { form: { fullName: 'E2E Cues', email, password: PASSWORD } },
            );
            expect(registered.status()).toBe(200);

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', { timeout: 20000 });

            await use();
        },
        { auto: true },
    ],
    seeded: async ({ page, signedIn }, use) => {
        expect(signedIn).toBeUndefined();
        const seeded = await page.request.post('/dev/seed/document', {
            form: { title: 'Scroll cues', markdown: '# Cues' },
        });
        expect(seeded.status()).toBe(201);
        const body = await seeded.json();

        await use({
            projectId: body.projectId as string,
            documentId: body.documentId as string,
        });
    },
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
});

const cueOf = (page: Page, table: string): Locator =>
    page.locator(`.lp-table-cue:has(${table})`);

async function scrollLeftOf(locator: Locator): Promise<number> {
    return locator.evaluate((element) => element.scrollLeft);
}

test('the documents table draws arrows that scroll it', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);
    const table = page.locator('.lp-data-table--documents');
    const cue = cueOf(page, '.lp-data-table--documents');
    const next = cue.locator('.lp-overflow-cue__arrow--next');
    const prev = cue.locator('.lp-overflow-cue__arrow--prev');

    await expect(next).toBeVisible();
    await expect(prev).toBeHidden();
    expect(await scrollLeftOf(table)).toBe(0);
    await expect(cue).not.toHaveAttribute('data-overflow-start', /.*/);

    const header = (await table
        .locator('.lp-data-table__header')
        .boundingBox())!;
    const arrow = (await next.boundingBox())!;
    expect(Math.abs(arrow.height - header.height)).toBeLessThanOrEqual(1);
    expect(Math.abs(arrow.y - header.y)).toBeLessThanOrEqual(1);

    await next.tap();

    await expect(prev).toBeVisible();
    await expect.poll(() => scrollLeftOf(table)).toBeGreaterThan(0);
    await expect(cue).toHaveAttribute('data-overflow-start', '');
});

test('the arrows leave when the table fits', async ({ page, seeded }) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);
    const cue = cueOf(page, '.lp-data-table--documents');

    await expect(cue.locator('.lp-overflow-cue__arrow--next')).toBeVisible();

    await page.setViewportSize({ width: 1400, height: 900 });

    await expect(cue.locator('.lp-overflow-cue__arrow--next')).toBeHidden();
    await expect(cue.locator('.lp-overflow-cue__arrow--prev')).toBeHidden();
});

test('the version history table draws the arrows', async ({ page, seeded }) => {
    await page.goto(
        `/projects/${seeded.projectId}/documents/${seeded.documentId}/review/history`,
    );
    const table = page.locator('.lp-data-table--history');
    const cue = cueOf(page, '.lp-data-table--history');

    await expect(table.locator('[data-version-number]')).toHaveCount(1);
    await expect(cue.locator('.lp-overflow-cue__arrow--next')).toBeVisible();
    await cue.locator('.lp-overflow-cue__arrow--next').tap();
    await expect(cue.locator('.lp-overflow-cue__arrow--prev')).toBeVisible();
    await expect.poll(() => scrollLeftOf(table)).toBeGreaterThan(0);
});

async function openConnectStep(page: Page): Promise<Locator> {
    await page.getByLabel(/Project name/i).fill('Cues project');
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Create project' }),
        '/welcome/project',
    );
    await expect(page).toHaveURL(/\/welcome\/connect$/);

    return page
        .locator('.lp-code-dark')
        .filter({ has: page.getByTestId('wizard-init-command') });
}

test('a setup command fades its right edge and draws no arrows', async ({
    page,
}) => {
    const block = await openConnectStep(page);
    const scroll = block.locator('.lp-code-dark__scroll');
    const body = block.locator('.lp-code-dark__body');

    await expect(scroll).toHaveAttribute('data-overflow-end', '');
    await expect(scroll).not.toHaveAttribute('data-overflow-start', /.*/);
    await expect(block.locator('.lp-overflow-cue__arrow')).toHaveCount(0);
    await expect
        .poll(() => body.evaluate((e) => getComputedStyle(e).maskImage))
        .toContain('linear-gradient');

    await body.evaluate((element) => {
        element.scrollLeft = element.scrollWidth;
    });

    await expect(scroll).not.toHaveAttribute('data-overflow-end', /.*/);
    await expect
        .poll(() => body.evaluate((e) => getComputedStyle(e).maskImage))
        .toBe('none');
    await expect(block.getByTestId('wizard-init-command')).toContainText(
        /^loupe init --project [0-9a-f-]{36}$/,
    );
});

test('a setup command that fits has no fade', async ({ page }) => {
    await page.setViewportSize({ width: 1400, height: 900 });
    const block = await openConnectStep(page);

    await expect(block).toBeVisible();
    await expect(block.locator('.lp-code-dark__scroll')).not.toHaveAttribute(
        'data-overflow-end',
        /.*/,
    );
    expect(
        await block
            .locator('.lp-code-dark__body')
            .evaluate((e) => getComputedStyle(e).maskImage),
    ).toBe('none');
});
