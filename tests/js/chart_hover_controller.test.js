/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import ChartHoverController from '../../assets/controllers/chart_hover_controller.js';

let application;

const markup = `<figure data-controller="chart-hover" data-action="keydown.esc->chart-hover#hide">
    <div data-chart-hover-target="surface">
        <svg>
            <g id="first" tabindex="0" aria-describedby="hover-0"
               data-action="pointerenter->chart-hover#show pointerleave->chart-hover#hide focus->chart-hover#show blur->chart-hover#hide"></g>
            <g id="second" tabindex="0" aria-describedby="hover-1"
               data-action="pointerenter->chart-hover#show pointerleave->chart-hover#hide focus->chart-hover#show blur->chart-hover#hide"></g>
            <g id="bare" tabindex="0" aria-describedby="missing"
               data-action="focus->chart-hover#show blur->chart-hover#hide"></g>
        </svg>
    </div>
    <template id="hover-0"><div class="card" data-card="first">First</div></template>
    <template id="hover-1"><div class="card" data-card="second">Second</div></template>
</figure>`;

async function mount() {
    document.body.innerHTML = markup;
    application = Application.start();
    application.register('chart-hover', ChartHoverController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function rect(left, top, width, height) {
    return {
        left,
        top,
        width,
        height,
        right: left + width,
        bottom: top + height,
        x: left,
        y: top,
    };
}

function stub(selector, box) {
    document.querySelector(selector).getBoundingClientRect = () => box;
}

function shown() {
    return [...document.querySelectorAll('[data-chart-hover-card]')];
}

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('shows the card of a target on focus and hides it on blur', async () => {
    await mount();
    const first = document.getElementById('first');
    first.dispatchEvent(new Event('focus'));
    expect(shown()).toHaveLength(1);
    expect(shown()[0].textContent).toBe('First');
    first.dispatchEvent(new Event('blur'));
    expect(shown()).toHaveLength(0);
});

it('shows the card on pointer enter and hides it on pointer leave', async () => {
    await mount();
    const second = document.getElementById('second');
    second.dispatchEvent(new Event('pointerenter'));
    expect(shown().map((card) => card.textContent)).toEqual(['Second']);
    second.dispatchEvent(new Event('pointerleave'));
    expect(shown()).toHaveLength(0);
});

it('keeps one card at a time', async () => {
    await mount();
    document.getElementById('first').dispatchEvent(new Event('pointerenter'));
    document.getElementById('second').dispatchEvent(new Event('pointerenter'));
    expect(shown().map((card) => card.textContent)).toEqual(['Second']);
});

it('hides the card on Escape', async () => {
    await mount();
    document.getElementById('first').dispatchEvent(new Event('focus'));
    document
        .querySelector('figure')
        .dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
    expect(shown()).toHaveLength(0);
});

it('draws no card when the target names no template', async () => {
    await mount();
    document.getElementById('bare').dispatchEvent(new Event('focus'));
    expect(shown()).toHaveLength(0);
});

it('puts the card to the right of the target', async () => {
    await mount();
    stub('[data-chart-hover-target="surface"]', rect(0, 0, 1000, 300));
    stub('#first', rect(100, 20, 100, 200));
    const original = HTMLElement.prototype.getBoundingClientRect;
    HTMLElement.prototype.getBoundingClientRect = function () {
        return this.hasAttribute('data-chart-hover-card')
            ? rect(0, 0, 200, 80)
            : original.call(this);
    };
    try {
        document.getElementById('first').dispatchEvent(new Event('focus'));
        const card = shown()[0];
        expect(parseFloat(card.style.left)).toBeGreaterThan(150);
        expect(card.style.top).toBe('20px');
    } finally {
        HTMLElement.prototype.getBoundingClientRect = original;
    }
});

it('flips the card to the left near the right edge', async () => {
    await mount();
    stub('[data-chart-hover-target="surface"]', rect(0, 0, 1000, 300));
    stub('#first', rect(850, 20, 100, 200));
    const original = HTMLElement.prototype.getBoundingClientRect;
    HTMLElement.prototype.getBoundingClientRect = function () {
        return this.hasAttribute('data-chart-hover-card')
            ? rect(0, 0, 200, 80)
            : original.call(this);
    };
    try {
        document.getElementById('first').dispatchEvent(new Event('focus'));
        const card = shown()[0];
        expect(parseFloat(card.style.left) + 200).toBeLessThan(900);
    } finally {
        HTMLElement.prototype.getBoundingClientRect = original;
    }
});
