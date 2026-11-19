import { expect, type Locator, type Page } from '@playwright/test';

type RenderLog = Window & { __morphRenders?: string[] };

/** Records the render method of every Turbo page render, from the first document on. */
export async function recordRenders(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const log = window as RenderLog;
        log.__morphRenders = [];
        document.addEventListener('turbo:before-render', (event) => {
            const detail = (event as CustomEvent<{ renderMethod?: string }>)
                .detail;
            log.__morphRenders?.push(detail.renderMethod ?? 'unknown');
        });
    });
}

/** Clears the render log, so the next check reads only the renders of one action. */
export async function resetRenders(page: Page): Promise<void> {
    await page.evaluate(() => {
        (window as RenderLog).__morphRenders = [];
    });
}

/** Soft-checks that the last action rendered the page with a morph. */
export async function expectMorphed(page: Page): Promise<void> {
    const renders = await page.evaluate(
        () => (window as RenderLog).__morphRenders ?? [],
    );
    expect
        .soft(renders, 'the action rendered the page with a morph')
        .toContain('morph');
}

/** No dialog is left modal, and a control outside every dialog takes a click. */
export async function expectPageInteractive(
    page: Page,
    outside: Locator,
): Promise<void> {
    await expect
        .poll(
            () =>
                page.evaluate(() => document.querySelector(':modal') === null),
            {
                message: 'no element matches :modal after the submit',
                timeout: 2_000,
            },
        )
        .toBe(true);
    await outside.click({ timeout: 2_000 });
}

/** Waits until every animation of an element ends, such as a dialog that opens. */
export async function settled(locator: Locator): Promise<void> {
    await locator.evaluate((element) =>
        Promise.all(
            element
                .getAnimations()
                .map((animation) => animation.finished.catch(() => {})),
        ),
    );
}
