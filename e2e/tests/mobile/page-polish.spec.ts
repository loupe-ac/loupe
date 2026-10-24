/**
 * The page polish fixes at a 390px phone with a touch pointer: the board column
 * settings rows, the inbox list margin, the workshop ask row and the card bar.
 * Each test reads boxes from the page, because the faults were layout faults
 * that no text assertion sees.
 */

import { expect, type Locator, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eMobilePolish1!';

const PHONE = { width: 390, height: 844 };
// Exactly 60 characters, the longest ask title the workshop row must fit in two lines.
const LONG_TITLE =
    'Which export format should the nightly analytics report use?';

interface Seeded {
    projectId: string;
}

async function box(locator: Locator) {
    const found = await locator.boundingBox();
    expect(found).not.toBeNull();
    return found!;
}

async function setInbox(page: Page, enabled: 0 | 1): Promise<void> {
    const response = await page.request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled },
    });
    expect(response.ok()).toBeTruthy();
}

const test = base.extend<{ seeded: Seeded }>({
    seeded: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+polish+${tag}+${RUN}@example.com`;
            const registered = await page.request.post(
                '/dev/register-and-verify',
                {
                    form: {
                        fullName: 'E2E Polish',
                        email,
                        password: PASSWORD,
                    },
                },
            );
            expect(registered.status()).toBe(200);

            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(PASSWORD);
            await page.getByRole('button', { name: 'Sign in' }).click();
            await expect(page).toHaveURL('/welcome', { timeout: 20000 });

            await setInbox(page, 1);
            const seeded = await page.request.post('/dev/seed/inbox', {
                form: { questionTitle: LONG_TITLE },
            });
            expect(seeded.status()).toBe(201);
            await use({ projectId: (await seeded.json()).projectId as string });
            await setInbox(page, 0);
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: PHONE,
    hasTouch: true,
    isMobile: true,
});

test('each board column settings row keeps its name and its icons on one line', async ({
    page,
    seeded,
}) => {
    test.slow();
    await page.goto(`/projects/${seeded.projectId}/settings/columns`);
    const rows = page.locator('.lp-settings-column');
    expect(await rows.count()).toBeGreaterThan(1);

    for (const row of await rows.all()) {
        const rowBox = await box(row);
        const name = await box(row.locator('.lp-settings-column__name'));
        const buttons = row.locator('button[data-action="click->modal#open"]');
        const settings = await box(buttons.first());
        expect(name.y).toBeLessThan(settings.y + settings.height);
        if ((await buttons.count()) > 1) {
            const remove = await box(buttons.last());
            expect(Math.abs(remove.y - settings.y)).toBeLessThanOrEqual(1);
            expect(remove.x).toBeGreaterThan(settings.x);
            expect(
                rowBox.x + rowBox.width - (remove.x + remove.width),
            ).toBeLessThan(24);
        }
        expect(name.x + name.width).toBeLessThanOrEqual(settings.x + 1);
    }

    const add = await box(
        page.getByRole('button', { name: 'Add a column', exact: true }),
    );
    const heading = await box(page.getByRole('heading', { level: 3 }).last());
    expect(heading.y - (add.y + add.height)).toBeGreaterThanOrEqual(16);
});

test('the inbox list keeps the same margin on the right as on the left', async ({
    page,
    seeded,
}) => {
    test.slow();
    await page.goto(`/projects/${seeded.projectId}/inbox`);
    const filter = page.locator('.lp-inbox-filter');
    await expect(filter).toBeVisible();
    const filterBox = await box(filter);
    const left = filterBox.x;
    const right = PHONE.width - (filterBox.x + filterBox.width);
    expect(left).toBeGreaterThanOrEqual(12);
    expect(Math.abs(right - left)).toBeLessThanOrEqual(1);
});

test('a workshop ask puts its chip and its age under a long title', async ({
    page,
    seeded,
}) => {
    test.slow();
    expect(LONG_TITLE).toHaveLength(60);
    await page.goto(`/projects/${seeded.projectId}`);
    const row = page
        .locator('[data-workshop-attention]')
        .filter({ hasText: LONG_TITLE });
    await expect(row).toBeVisible();
    const title = await box(row.locator('strong'));
    const chip = await box(row.locator('.lp-workshop-attention__badge'));
    const age = await box(row.locator('time'));
    expect(chip.y).toBeGreaterThanOrEqual(title.y + title.height - 1);
    expect(chip.x).toBeLessThan(PHONE.width / 2);
    expect(age.x).toBeGreaterThan(chip.x + chip.width);
    expect(age.y).toBeGreaterThanOrEqual(title.y + title.height - 1);
    const lineHeight = await row
        .locator('strong')
        .evaluate((element) =>
            parseFloat(getComputedStyle(element).lineHeight),
        );
    expect(title.height).toBeLessThanOrEqual(lineHeight * 2 + 1);
});

test('the card page and its edit page show one bar under the top bar', async ({
    page,
    seeded,
}) => {
    test.slow();
    await page.goto(`/projects/${seeded.projectId}/board/cards/new`);
    await page.getByLabel('Title', { exact: true }).fill('Card bar on a phone');
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Card bar on a phone' }),
    ).toBeVisible({ timeout: 15000 });

    const topbar = page.locator('.lp-topbar');
    const header = page.locator('.lp-card-drawer__header');
    const edit = header.getByRole('link', { name: 'Edit card' });

    const topbarBox = await box(topbar);
    const headerBox = await box(header);
    expect(topbarBox.height + headerBox.height).toBeLessThanOrEqual(100);

    const number = await box(header.locator('.lp-card-number'));
    const editBox = await box(edit);
    const centre = (found: { y: number; height: number }) =>
        found.y + found.height / 2;
    expect(Math.abs(centre(number) - centre(editBox))).toBeLessThanOrEqual(2);
    expect(editBox.height).toBeGreaterThanOrEqual(44);
    expect(editBox.x + editBox.width).toBeLessThanOrEqual(PHONE.width);

    await edit.click();
    await expect(page).toHaveURL(/\/edit$/);
    const editHeader = page.locator('.lp-card-drawer__header');
    const editTopbar = await box(topbar);
    const editHeaderBox = await box(editHeader);
    expect(editTopbar.height + editHeaderBox.height).toBeLessThanOrEqual(100);
});
