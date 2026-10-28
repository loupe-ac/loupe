/**
 * Browser coverage for card references in rendered Markdown: a card link opens
 * the card drawer from an inbox body, a document and a card body inside the
 * drawer, and stays a full visit on the card page, which has no drawer.
 */

import { expect, type APIRequestContext, type Page } from '@playwright/test';
import {
    hubStubbedTest as test,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eReferenceLinks1!';

async function setFlag(
    request: APIRequestContext,
    name: string,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: {
            fullName: 'E2E Reference Links User',
            email,
            password: PASSWORD,
        },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

async function createCard(
    page: Page,
    projectId: string,
    title: string,
    body: string,
): Promise<{ path: string; number: string }> {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Column').selectOption({ label: 'Backlog' });
    await page.locator('textarea[name="create_card_form[body]"]').fill(body);
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    const number = (await page.locator('.lp-card-number').first().textContent())
        ?.trim()
        .replace('#', '');
    expect(number).toMatch(/^\d+$/);

    return { path: new URL(page.url()).pathname, number: number ?? '' };
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

test.slow();

// The flag is global, so it goes back to its shipped value for later specs.
test.afterAll(async ({ request }) => {
    await setFlag(request, 'inbox.enabled', false);
});

test('a card reference opens the drawer where the page has one, and the card page where it has none', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setFlag(page.request, 'inbox.enabled', true);
    await registerAndLogin(page, `e2e+reference+links+${RUN}@example.com`);
    const seededProject = await page.request.post('/dev/seed/document', {
        form: { title: 'Reference links project', markdown: '# Links' },
    });
    expect(seededProject.status()).toBe(201);
    const { projectId } = await seededProject.json();

    const target = await createCard(
        page,
        projectId,
        `Reference target ${RUN}`,
        'The card that the others name.',
    );
    const source = await createCard(
        page,
        projectId,
        `Reference source ${RUN}`,
        `Builds on card:${target.number} first.`,
    );
    const document = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Reference links document',
            markdown: `Child card:${source.number} builds that work.`,
        },
    });
    expect(document.status()).toBe(201);
    const { documentId } = await document.json();
    const inbox = await page.request.post('/dev/seed/inbox', {
        form: { context: `Working on card:${target.number} now.` },
    });
    expect(inbox.status()).toBe(201);
    const { questionNumber } = await inbox.json();

    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    const drawerTitle = drawer.locator('.lp-card-drawer__title');

    const tooltip = page.locator('#lp-reference-tooltip');

    // The inbox opens a reference in its ask context in the card drawer.
    const inboxUrl = `/projects/${projectId}/inbox?item=${questionNumber}`;
    await page.goto(inboxUrl);
    await page
        .locator('.lp-inbox-ask__context')
        .getByRole('link', { name: `card #${target.number}`, exact: true })
        .hover();
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText(`Reference target ${RUN}`);
    await page
        .locator('.lp-inbox-ask__context')
        .getByRole('link', { name: `card #${target.number}`, exact: true })
        .click();
    await expect(drawer).toHaveJSProperty('open', true);
    await expect(drawerTitle).toHaveText(`Reference target ${RUN}`);
    await expect(page).toHaveURL(inboxUrl);

    // A document opens the card in its drawer, and a reference in that card body opens the next card there.
    const documentUrl = `/projects/${projectId}/documents/${documentId}/review`;
    await page.goto(documentUrl);
    await page
        .locator('.lp-review-doc__prose[data-references]')
        .getByRole('link', { name: `card #${source.number}`, exact: true })
        .click();
    await expect(drawer).toHaveJSProperty('open', true);
    await expect(drawerTitle).toHaveText(`Reference source ${RUN}`);
    // The drawer is a modal dialog, and the tooltip still shows above it.
    const drawerLink = drawer
        .locator('.lp-card-detail__body')
        .getByRole('link', { name: `card #${target.number}`, exact: true });
    await drawerLink.hover();
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText(`Reference target ${RUN}`);
    await drawerLink.click();
    await expect(drawerTitle).toHaveText(`Reference target ${RUN}`);
    await expect(page).toHaveURL(documentUrl);

    // The card page has no drawer, so the reference loads the other card page.
    await page.goto(source.path);
    await page
        .locator('.lp-card-detail__body')
        .getByRole('link', { name: `card #${target.number}`, exact: true })
        .click();
    await expect(page).toHaveURL(target.path);
    await expect(
        page.getByRole('heading', { name: `Reference target ${RUN}` }),
    ).toBeVisible();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
});
