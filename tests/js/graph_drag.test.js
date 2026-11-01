/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import GraphDragController, {
    DRAG_THRESHOLD,
    indexAtPoint,
    stepsBetween,
} from '../../assets/controllers/graph_drag_controller.js';

const rectangle = (left, top, right, bottom) => ({ left, top, right, bottom });
const GRID = [
    rectangle(0, 0, 100, 100),
    rectangle(110, 0, 210, 100),
    rectangle(0, 110, 210, 210),
];

it('counts the steps as the new index minus the old one', () => {
    expect(stepsBetween(0, 2)).toBe(2);
    expect(stepsBetween(2, 0)).toBe(-2);
    expect(stepsBetween(1, 1)).toBe(0);
});

it('finds the tile under the point', () => {
    expect(indexAtPoint(GRID, 50, 50)).toBe(0);
    expect(indexAtPoint(GRID, 150, 50)).toBe(1);
    expect(indexAtPoint(GRID, 150, 150)).toBe(2);
});

it('takes the nearest tile for a point in a gap or outside the grid', () => {
    expect(indexAtPoint(GRID, 105, 50)).toBe(0);
    expect(indexAtPoint(GRID, 109, 50)).toBe(1);
    expect(indexAtPoint(GRID, 300, 300)).toBe(2);
    expect(indexAtPoint(GRID, -50, -50)).toBe(0);
});

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function cell(id) {
    return `<turbo-frame data-graph-id="${id}">
        <span data-graph-grip></span>
        <form data-graph-drag-form><input type="hidden" data-graph-drag-steps value="0"></form>
    </turbo-frame>`;
}

function place(grid) {
    [...grid.children].forEach((child, index) => {
        child.getBoundingClientRect = () => ({
            left: 0,
            right: 100,
            top: index * 110,
            bottom: index * 110 + 100,
        });
    });
}

function pointer(type, target, y, extra = {}) {
    target.dispatchEvent(
        new MouseEvent(type, {
            bubbles: true,
            clientX: 50,
            clientY: y,
            button: 0,
            ...extra,
        }),
    );
}

let submitted;

beforeEach(async () => {
    submitted = [];
    document.body.innerHTML = `<div id="grid" data-controller="graph-drag">${cell('a')}${cell('b')}${cell('c')}<div><a href="#">Add</a></div></div>`;
    HTMLFormElement.prototype.requestSubmit = function requestSubmit() {
        submitted.push(this.querySelector('[data-graph-drag-steps]').value);
    };
    application = Application.start();
    application.register('graph-drag', GraphDragController);
    await settle();
    place(document.getElementById('grid'));
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

const grip = (index) => document.querySelectorAll('[data-graph-grip]')[index];

it('posts the step count when a tile drops on another place', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    expect(document.getElementById('grid').hasAttribute('data-dragging')).toBe(
        true,
    );
    pointer('pointerup', grip(0), 270);

    expect(submitted).toEqual(['2']);
    expect(document.getElementById('grid').hasAttribute('data-dragging')).toBe(
        false,
    );
});

it('counts a move up as negative steps', () => {
    pointer('pointerdown', grip(2), 270);
    pointer('pointermove', grip(2), 200);
    pointer('pointerup', grip(2), 60);

    expect(submitted).toEqual(['-2']);
});

it('posts nothing for a press that stays under the threshold', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 50 + DRAG_THRESHOLD - 1);
    pointer('pointerup', grip(0), 160);

    expect(submitted).toEqual([]);
    expect(document.getElementById('grid').hasAttribute('data-dragging')).toBe(
        false,
    );
});

it('posts nothing for a drop on the same place', () => {
    pointer('pointerdown', grip(1), 160);
    pointer('pointermove', grip(1), 200);
    pointer('pointerup', grip(1), 160);

    expect(submitted).toEqual([]);
});

it('posts nothing when the browser cancels the gesture', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    pointer('pointercancel', grip(0), 270);
    pointer('pointerup', grip(0), 270);

    expect(submitted).toEqual([]);
    expect(document.getElementById('grid').hasAttribute('data-dragging')).toBe(
        false,
    );
});

it('ignores a press that does not start on a grip', () => {
    const add = document.querySelector('a');
    pointer('pointerdown', add, 50);
    pointer('pointermove', add, 200);
    pointer('pointerup', add, 270);

    expect(submitted).toEqual([]);
});

const grid = () => document.getElementById('grid');
const lifted = () => grid().querySelector('.lp-dashboard-grid__lifted');
const slot = () => grid().querySelector('.lp-dashboard-grid__placeholder');
const order = () =>
    [...grid().children]
        .map((child) => child.dataset.graphId)
        .filter((id) => id !== undefined);

it('lifts a copy that follows the pointer and carries no controller hooks', () => {
    grip(0)
        .closest('[data-graph-id]')
        .insertAdjacentHTML(
            'beforeend',
            '<div id="inner" data-controller="chart-hover"><template></template></div>',
        );
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 80);

    expect(lifted().style.top).toBe('30px');
    pointer('pointermove', grip(0), 90);
    expect(lifted().style.top).toBe('40px');
    expect(lifted().inert).toBe(true);
    expect(
        lifted().querySelector('form, template, [id], [data-controller]'),
    ).toBe(null);
    expect(
        grip(0)
            .closest('[data-graph-id]')
            .classList.contains('lp-dashboard-grid__cell--dragging'),
    ).toBe(true);
});

it('marks the landing place after a later tile and before an earlier one', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    expect(slot().previousElementSibling.dataset.graphId).toBe('b');

    pointer('pointermove', grip(0), 50);
    expect(slot()).toBe(null);
    pointer('pointercancel', grip(0), 50);

    pointer('pointerdown', grip(2), 270);
    pointer('pointermove', grip(2), 160);
    expect(slot().nextElementSibling.dataset.graphId).toBe('b');
});

it('keeps the marked place while the pointer rests on the slot', () => {
    pointer('pointerdown', grip(2), 270);
    pointer('pointermove', grip(2), 160);
    slot().getBoundingClientRect = () => ({
        left: 0,
        right: 100,
        top: 0,
        bottom: 400,
    });
    pointer('pointermove', grip(2), 50);
    expect(slot().nextElementSibling.dataset.graphId).toBe('b');

    pointer('pointerup', grip(2), 50);
    expect(submitted).toEqual(['-1']);
});

it('moves the tile at once on a drop and leaves no drag marks', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    pointer('pointerup', grip(0), 160);

    expect(order()).toEqual(['b', 'a', 'c']);
    expect(lifted()).toBe(null);
    expect(slot()).toBe(null);
});

it('puts the tile back when the server refuses the move', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    pointer('pointerup', grip(0), 160);
    const form = grid().querySelector('[data-graph-id="a"] form');
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { success: false },
        }),
    );

    expect(order()).toEqual(['a', 'b', 'c']);
});

it('refuses a second drag until the move answers', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    pointer('pointerup', grip(0), 160);
    const form = grid().querySelector('[data-graph-id="a"] form');

    pointer('pointerdown', grip(2), 270);
    pointer('pointermove', grip(2), 50);
    expect(lifted()).toBe(null);
    pointer('pointerup', grip(2), 50);

    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { success: true },
        }),
    );
    pointer('pointerdown', grip(2), 270);
    pointer('pointermove', grip(2), 160);
    expect(lifted()).not.toBe(null);
});

it('drops nothing when Escape ends the drag', () => {
    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

    expect(lifted()).toBe(null);
    expect(slot()).toBe(null);
    pointer('pointerup', grip(0), 160);
    expect(submitted).toEqual([]);
    expect(order()).toEqual(['a', 'b', 'c']);
});

it('scrolls the page while the pointer rests near its bottom edge', () => {
    const frames = [];
    const request = vi
        .spyOn(window, 'requestAnimationFrame')
        .mockImplementation((callback) => frames.push(callback));
    const scroller = document.createElement('div');
    scroller.style.overflowY = 'auto';
    grid().before(scroller);
    scroller.append(grid());
    scroller.getBoundingClientRect = () => ({ top: 0, bottom: 300 });

    pointer('pointerdown', grip(0), 50);
    pointer('pointermove', grip(0), 160);
    expect(frames).toHaveLength(0);

    pointer('pointermove', grip(0), 290);
    expect(frames).toHaveLength(1);
    frames.shift()();
    expect(scroller.scrollTop).toBeGreaterThan(0);

    pointer('pointercancel', grip(0), 290);
    request.mockRestore();
});
