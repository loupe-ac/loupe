import { expect, type Page } from '@playwright/test';
import { createTest, expectInPlace, suppressWidget } from '../fixtures';

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
    const wrapper = page.locator(
        'single' === row
            ? '[data-metric-formula-target="single"] .ts-wrapper'
            : `[data-letter="${row}"] .ts-wrapper`,
    );
    const field = wrapper.locator('.ts-control');
    // Choosing a metric in one row narrows the lists of the others, which can close a dropdown
    // that opened a moment before. Open it again until the option shows.
    await expect(async () => {
        await field.click();
        await field.locator('input').fill(search);
        await wrapper
            .locator('div[role="option"]')
            .getByText(label, { exact: true })
            .click({ timeout: 2000 });
    }).toPass({ timeout: 15000 });
    await expect(wrapper.locator('.item')).toHaveText(label);
    await expect(wrapper).not.toHaveClass(/dropdown-active/);
}

/** Picks the first other option a control allows, and returns its value. */
async function pickAnother(page: Page, label: string): Promise<string> {
    const select = page.getByLabel(label, { exact: true });
    // 30 days allows a second Bucket and Previous period, which 7 days and All time do not.
    if ('Range' === label) {
        await select.selectOption('thirty-days');

        return 'thirty-days';
    }
    const value = await select.evaluate(
        (element: HTMLSelectElement) =>
            [...element.options].find(
                (option) => !option.disabled && !option.selected,
            )!.value,
    );
    await select.selectOption(value);

    return value;
}

/** Marks the current result, so a test can tell when the frame brings a new one. */
async function markResult(page: Page): Promise<void> {
    await page.evaluate(() => {
        (
            document.querySelector('turbo-frame#metrics-result')!
                .firstElementChild as HTMLElement & { old?: boolean }
        ).old = true;
    });
}

async function expectNewResult(page: Page): Promise<void> {
    await expect
        .poll(() =>
            page.evaluate(
                () =>
                    (
                        document.querySelector('turbo-frame#metrics-result')!
                            .firstElementChild as HTMLElement & {
                            old?: boolean;
                        }
                    ).old !== true,
            ),
        )
        .toBe(true);
}

test('every single-metric control updates the result in place and keeps the URL', async ({
    page,
}) => {
    // Each control waits for its own answer from the server.
    test.setTimeout(test.info().timeout * 3);
    await openMetrics(page);

    await expectInPlace(page, async () => {
        await markResult(page);
        await chooseMetric(page, 'single', 'stop rate', 'Stop rate');
        await expect(page).toHaveURL(/metric=stop-rate/);
        await expectNewResult(page);
        // The frame answer brings the Statistic and Unit lists of Stop rate.
        await expect(page.locator('#metrics-statistic option')).toHaveText([
            'Mean',
            'Count',
        ]);
        await expect(page.locator('#metrics-unit option')).toHaveText([
            'Per run',
        ]);

        for (const label of [
            'Statistic',
            'Group',
            'Range',
            'Bucket',
            'Compare to',
            'Chart',
        ]) {
            await markResult(page);
            const name = await page
                .getByLabel(label, { exact: true })
                .getAttribute('name');
            const value = await pickAnother(page, label);
            await expect(page).toHaveURL(new RegExp(`${name}=${value}`));
            await expectNewResult(page);
        }
    });

    await page.reload();
    await expect(page.locator('#metrics-metric')).toHaveValue('stop-rate');
});

test('a change shows the result busy until its answer renders', async ({
    page,
}) => {
    await openMetrics(page);
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => (release = resolve));
    await page.route(
        (url) => url.pathname.endsWith('/analytics/metrics'),
        async (route) => {
            if ('metrics-result' === route.request().headers()['turbo-frame']) {
                await held;
            }
            await route.continue();
        },
    );
    const result = page.locator('[data-metrics-result]');
    await expect(result).not.toHaveAttribute('aria-busy');

    await expectInPlace(page, async () => {
        await page.getByLabel('Unit', { exact: true }).selectOption('run');
        await expect(result).toHaveAttribute('aria-busy', 'true');
        await expect(page.locator('[data-metrics-loading]')).toBeVisible();
        release();
        await expect(result).not.toHaveAttribute('aria-busy');
        await expect(page.locator('[data-metrics-loading]')).toBeHidden();
    });
});

test('a filled row keeps the empty rows next to it and updates the result', async ({
    page,
}) => {
    // Each control waits for its own answer from the server.
    test.setTimeout(test.info().timeout * 3);
    await openMetrics(page);

    await expectInPlace(page, async () => {
        await page.getByRole('button', { name: 'Add formula' }).click();
        await chooseMetric(page, 'A', 'cache read', 'Cache read tokens');
        await chooseMetric(page, 'B', 'cache write', 'Cache write tokens');
        await expect(page).toHaveURL(/rows=A%3Acache-read-tokens/);

        await page.getByRole('button', { name: 'Add metric' }).click();
        await page.getByRole('button', { name: 'Add metric' }).click();
        await expect(page.locator('[data-letter]')).toHaveCount(4);
        await markResult(page);
        await chooseMetric(page, 'C', 'cache read', 'Cache read tokens');
        const formula = page.getByLabel('Formula', { exact: true });
        await expect(page).toHaveURL(/C%3Acache-read-tokens%3Asum/);
        await formula.fill('(A + C) / B');
        await expect(page).toHaveURL(/formula=%28A\+%2B\+C%29\+%2F\+B/);
        await expectNewResult(page);

        await expect(page.locator('[data-letter]')).toHaveCount(4);
        await expect(
            page.locator(
                '[data-letter="D"] [data-metric-formula-role="metric"]',
            ),
        ).toHaveValue('');
        await expect(formula).toBeFocused();

        await markResult(page);
        await page
            .locator('[data-letter="B"] [data-metric-formula-role="statistic"]')
            .selectOption('count');
        await expect(page).toHaveURL(/B%3Acache-write-tokens%3Acount/);
        await expectNewResult(page);

        await markResult(page);
        await page
            .getByLabel('Format', { exact: true })
            .selectOption('percent');
        await expect(page).toHaveURL(/format=percent/);
        await expectNewResult(page);

        await page.locator('[data-letter="D"] button').click();
        await expect(page.locator('[data-letter]')).toHaveCount(3);
        await expect(page.locator('[data-letter="C"]')).toBeVisible();
    });
});

test('a formula builds in the page and closes onto the earliest row', async ({
    page,
}) => {
    await openMetrics(page);

    await expectInPlace(page, async () => {
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
        await expect(page.locator('#metrics-metric')).toHaveValue(
            'cache-write-tokens',
        );
    });
});

test('a broken formula shows its error, keeps the page and blocks the submit', async ({
    page,
}) => {
    await openMetrics(page);
    await page.getByRole('button', { name: 'Add formula' }).click();
    await chooseMetric(page, 'A', 'cache read', 'Cache read tokens');
    await chooseMetric(page, 'B', 'cache write', 'Cache write tokens');
    await expect(page).toHaveURL(/rows=/);

    // The seeded project has no runs, so no graph or Save button exists. Stand-ins let the page
    // show how an invalid formula dims the last graph and disables Save.
    await page.evaluate(() => {
        document
            .querySelector('turbo-frame#metrics-result')!
            .insertAdjacentHTML(
                'beforeend',
                '<section class="lp-metric-graph">Last graph</section><button type="submit" data-save-new>Save</button>',
            );
    });
    const formula = page.getByLabel('Formula', { exact: true });
    await formula.fill('A / (B');
    await expect(page.locator('[data-save-new]')).toBeDisabled();
    await expect(page.getByRole('alert')).toContainText('parenthesis');
    await expect(page.locator('[data-metrics-form]')).toHaveAttribute(
        'data-formula-invalid',
        '',
    );
    await expect(page.locator('.lp-metric-graph')).toHaveCSS('opacity', '0.5');
    await expect(page.locator('[data-save-new]')).toBeDisabled();
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
