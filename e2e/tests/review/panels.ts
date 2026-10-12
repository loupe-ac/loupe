import { expect, type Locator, type Page } from '@playwright/test';

export const PANELS_KEY = 'loupe.review.panels';

export type PanelName = 'Decisions' | 'Comments' | 'Outline';

/** A toolbar button of the review page, by the panel it switches. */
export function panelButton(page: Page, name: PanelName) {
    return page.locator(
        `.lp-review-toolbar [aria-controls="review-panel-${name.toLowerCase()}"]`,
    );
}

/**
 * Clicks a toggle until its attribute reads true. The page can set the state
 * itself between the read and the click, such as a submit that reveals the
 * Comments panel, and the click then turns it off again.
 */
export async function toggleOn(
    toggle: Locator,
    attribute: 'aria-pressed' | 'aria-expanded',
): Promise<void> {
    await expect(async () => {
        if ((await toggle.getAttribute(attribute)) !== 'true') {
            await toggle.click();
        }
        await expect(toggle).toHaveAttribute(attribute, 'true', {
            timeout: 1000,
        });
    }).toPass({ timeout: 10_000 });
}

/** Switches a review panel on, unless it is on already. */
export async function showPanel(page: Page, name: PanelName): Promise<void> {
    await toggleOn(panelButton(page, name), 'aria-pressed');
}

/** The Comments panel row of the thread whose row shows this text. */
export function commentRow(page: Page, text: string) {
    return page.locator('#comment-rows .lp-comment-row').filter({
        hasText: text,
    });
}

/** The thread card a row opens. It resolves again after a stream swaps the card. */
export async function threadOf(page: Page, text: string) {
    const id = await commentRow(page, text).getAttribute('aria-controls');

    return page.locator(`#${id}`);
}

/** Opens the thread of a row as a popover, and returns the card. */
export async function openThread(page: Page, text: string) {
    await showPanel(page, 'Comments');
    const row = commentRow(page, text);
    const thread = await threadOf(page, text);
    await row.click();
    await expect(thread).toBeVisible();
    await expect(row).toHaveAttribute('aria-expanded', 'true');

    return thread;
}
