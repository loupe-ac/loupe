/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import InteractiveTooltipController from '../../assets/controllers/interactive_tooltip_controller.js';

let application;

const rectangle = (left, top, width, height) => ({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
});

beforeEach(async () => {
    vi.useFakeTimers();
    document.body.innerHTML = `
        <span data-controller="interactive-tooltip"
              data-action="pointerenter->interactive-tooltip#open pointerleave->interactive-tooltip#scheduleClose focusin->interactive-tooltip#open focusout->interactive-tooltip#leaveFocus keydown.esc->interactive-tooltip#close">
            <span id="mark" tabindex="0" data-action="pointerdown->interactive-tooltip#pressed"></span>
            <span id="tooltip" data-interactive-tooltip-target="tooltip"><a id="link" href="#">Open card</a></span>
        </span>
        <button id="outside"></button>`;
    document.querySelector('[data-controller]').getBoundingClientRect = () =>
        rectangle(400, 100, 16, 16);
    document.querySelector('#tooltip').getBoundingClientRect = () =>
        rectangle(0, 0, 288, 80);
    application = Application.start();
    application.register('interactive-tooltip', InteractiveTooltipController);
    await vi.advanceTimersByTimeAsync(0);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
});

const anchor = () => document.querySelector('[data-controller]');
const tooltip = () => document.querySelector('#tooltip');
const isOpen = () => tooltip().hasAttribute('data-open');

it('opens under the anchor, level with its right edge, when the pointer enters it', () => {
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(isOpen()).toBe(true);
    expect(tooltip().style.left).toBe('132px');
    expect(tooltip().style.top).toBe('124px');
});

it('opens level with the left edge of an anchor that aligns it to the start', () => {
    anchor().setAttribute('data-interactive-tooltip-align-value', 'start');
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(tooltip().style.left).toBe('396px');
});

it('stays open while the pointer crosses from the anchor into the tooltip', async () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    anchor().dispatchEvent(new Event('pointerleave'));
    await vi.advanceTimersByTimeAsync(100);
    anchor().dispatchEvent(new Event('pointerenter'));
    await vi.advanceTimersByTimeAsync(500);

    expect(isOpen()).toBe(true);
});

it('closes a moment after the pointer leaves', async () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    anchor().dispatchEvent(new Event('pointerleave'));
    await vi.advanceTimersByTimeAsync(200);

    expect(isOpen()).toBe(false);
});

it('stays open after a touch leaves, and closes on a press outside', async () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    const leave = new Event('pointerleave');
    leave.pointerType = 'touch';
    anchor().dispatchEvent(leave);
    await vi.advanceTimersByTimeAsync(500);
    expect(isOpen()).toBe(true);

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    expect(isOpen()).toBe(false);
});

it('flips above the anchor when the tooltip does not fit below', () => {
    anchor().getBoundingClientRect = () => rectangle(40, 700, 16, 16);
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(tooltip().style.top).toBe('612px');
});

it('stays inside the window when it fits neither below nor above', () => {
    const height = window.innerHeight;
    window.innerHeight = 120;
    anchor().getBoundingClientRect = () => rectangle(40, 50, 16, 16);
    anchor().dispatchEvent(new Event('pointerenter'));
    window.innerHeight = height;

    expect(tooltip().style.top).toBe('32px');
});

it('stays inside the right edge of the window', () => {
    anchor().getBoundingClientRect = () => rectangle(1010, 100, 16, 16);
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(tooltip().style.left).toBe('728px');
});

it('stays inside the left edge of the window', () => {
    anchor().getBoundingClientRect = () => rectangle(40, 100, 16, 16);
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(tooltip().style.left).toBe('8px');
});

it('stays open while the focus moves from the mark into the link', () => {
    const mark = document.querySelector('#mark');
    mark.focus();
    expect(isOpen()).toBe(true);

    anchor().dispatchEvent(
        new FocusEvent('focusout', {
            relatedTarget: document.querySelector('#link'),
        }),
    );
    expect(isOpen()).toBe(true);
});

it('closes when the focus leaves the anchor', () => {
    document.querySelector('#mark').focus();
    anchor().dispatchEvent(
        new FocusEvent('focusout', {
            relatedTarget: document.querySelector('#outside'),
        }),
    );

    expect(isOpen()).toBe(false);
});

it('moves the focus back to the mark when Escape closes the tooltip from its link', () => {
    document.querySelector('#mark').focus();
    anchor().dispatchEvent(new FocusEvent('focusin'));
    document.querySelector('#link').focus();
    document
        .querySelector('#link')
        .dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );

    expect(isOpen()).toBe(false);
    expect(document.activeElement).toBe(document.querySelector('#mark'));
});

it('closes on Escape', () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    anchor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

    expect(isOpen()).toBe(false);
});

it('closes on a mouse press of the mark, and not on a touch press', () => {
    const mark = document.querySelector('#mark');
    anchor().dispatchEvent(new Event('pointerenter'));

    const touch = new Event('pointerdown', { bubbles: true });
    touch.pointerType = 'touch';
    mark.dispatchEvent(touch);
    expect(isOpen()).toBe(true);

    const mouse = new Event('pointerdown', { bubbles: true });
    mouse.pointerType = 'mouse';
    mark.dispatchEvent(mouse);
    expect(isOpen()).toBe(false);
});

it('closes when a scroll moves the anchor', () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    window.dispatchEvent(new Event('scroll'));

    expect(isOpen()).toBe(false);
});

it('opens again in place after a morph drops its open mark and placement', () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    tooltip().removeAttribute('data-open');
    tooltip().style.left = '';
    tooltip().style.top = '';
    anchor().dispatchEvent(new Event('turbo:morph-element', { bubbles: true }));

    expect(isOpen()).toBe(true);
    expect(tooltip().style.left).toBe('132px');
    expect(tooltip().style.top).toBe('124px');
});

it('restores a morph once, and leaves the later morph events of the same update alone', () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    tooltip().removeAttribute('data-open');
    const place = vi.spyOn(tooltip(), 'getBoundingClientRect');
    anchor().dispatchEvent(new Event('turbo:morph-element', { bubbles: true }));
    const restored = place.mock.calls.length;
    anchor().dispatchEvent(new Event('turbo:morph-element', { bubbles: true }));

    expect(isOpen()).toBe(true);
    expect(restored).toBeGreaterThan(0);
    expect(place.mock.calls.length).toBe(restored);
});

it('does not open on a morph while it is closed', () => {
    anchor().dispatchEvent(new Event('turbo:morph-element', { bubbles: true }));

    expect(isOpen()).toBe(false);
});

describe('in the top layer', () => {
    let calls;

    beforeEach(() => {
        calls = [];
        tooltip().setAttribute('popover', 'manual');
        tooltip().showPopover = () => calls.push('show');
        tooltip().hidePopover = () => calls.push('hide');
        const measure = tooltip().getBoundingClientRect;
        tooltip().getBoundingClientRect = () => {
            calls.push('measure');

            return measure();
        };
    });

    it('shows the popover before it measures the tooltip to place it', () => {
        anchor().dispatchEvent(new Event('pointerenter'));

        expect(calls.slice(0, 2)).toEqual(['show', 'measure']);
        expect(isOpen()).toBe(true);
        expect(tooltip().style.left).toBe('132px');
    });

    it('hides the popover when the tooltip closes', () => {
        anchor().dispatchEvent(new Event('pointerenter'));
        anchor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(calls).toContain('hide');
        expect(isOpen()).toBe(false);
    });

    it('shows the popover once while it stays open', () => {
        anchor().dispatchEvent(new Event('pointerenter'));
        anchor().dispatchEvent(new Event('pointerenter'));

        expect(calls.filter((call) => call === 'show')).toHaveLength(1);
    });

    it('shows a tooltip that a morph replaced in the top layer again before it places it', () => {
        anchor().dispatchEvent(new Event('pointerenter'));
        const replacement = tooltip().cloneNode(true);
        replacement.removeAttribute('data-open');
        replacement.showPopover = () => calls.push('show again');
        replacement.getBoundingClientRect = () => {
            calls.push('measure again');

            return rectangle(0, 0, 288, 80);
        };
        tooltip().replaceWith(replacement);
        anchor().dispatchEvent(
            new Event('turbo:morph-element', { bubbles: true }),
        );

        expect(
            calls.filter((call) => call.endsWith('again')).slice(0, 2),
        ).toEqual(['show again', 'measure again']);
        expect(isOpen()).toBe(true);
        expect(tooltip().style.left).toBe('132px');
    });

    it('keeps the fixed tooltip when the browser has no popover support', () => {
        delete tooltip().showPopover;
        delete tooltip().hidePopover;
        anchor().dispatchEvent(new Event('pointerenter'));
        anchor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(calls).not.toContain('show');
        expect(calls).not.toContain('hide');
        expect(isOpen()).toBe(false);
    });
});

it('does not call the popover methods on a tooltip with no popover attribute', () => {
    const show = vi.fn();
    tooltip().showPopover = show;
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(show).not.toHaveBeenCalled();
    expect(isOpen()).toBe(true);
});
