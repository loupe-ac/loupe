/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DiffBarController from '../../assets/controllers/diff_bar_controller.js';
import DiffNavigationController from '../../assets/controllers/diff_navigation_controller.js';

let application;

const MARKUP = `
    <div class="lp-diff-bar" data-controller="diff-bar diff-navigation"
         data-diff-navigation-position-value="%current% of 3 changes"
         data-diff-navigation-total-value="3">
        <button id="line" aria-expanded="false" data-diff-bar-target="trigger"
                data-action="diff-bar#toggle"></button>
        <p data-diff-navigation-target="position">0/3</p>
        <button data-action="diff-navigation#next"></button>
        <i data-diff-navigation-target="hunk"></i>
        <i data-diff-navigation-target="hunk"></i>
        <i data-diff-navigation-target="hunk"></i>
    </div>`;

beforeEach(async () => {
    document.body.innerHTML = MARKUP;
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
    application = Application.start();
    application.register('diff-bar', DiffBarController);
    application.register('diff-navigation', DiffNavigationController);
    await Promise.resolve();
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('opens and closes the folded part from the line', () => {
    const bar = document.querySelector('.lp-diff-bar');
    const line = document.getElementById('line');

    line.click();
    expect(bar.hasAttribute('data-open')).toBe(true);
    expect(line.getAttribute('aria-expanded')).toBe('true');

    line.click();
    expect(bar.hasAttribute('data-open')).toBe(false);
    expect(line.getAttribute('aria-expanded')).toBe('false');
});

it('closes on Escape and gives the focus back to the line', () => {
    const bar = document.querySelector('.lp-diff-bar');
    const line = document.getElementById('line');
    line.click();

    const controller = application.getControllerForElementAndIdentifier(
        bar,
        'diff-bar',
    );
    controller.close();

    expect(bar.hasAttribute('data-open')).toBe(false);
    expect(document.activeElement).toBe(line);
});

it('writes the position of the current change as current/total', () => {
    const bar = document.querySelector('.lp-diff-bar');
    const controller = application.getControllerForElementAndIdentifier(
        bar,
        'diff-navigation',
    );
    controller.hunkTargets.forEach((hunk) => hunk.focus?.());

    controller.next();
    expect(
        document.querySelector('[data-diff-navigation-target="position"]')
            .textContent,
    ).toBe('1/3');
    controller.next();
    expect(
        document.querySelector('[data-diff-navigation-target="position"]')
            .textContent,
    ).toBe('2/3');
});
