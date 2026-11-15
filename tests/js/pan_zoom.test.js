import { expect, it } from 'vitest';
import {
    clampScale,
    fit,
    limits,
    pan,
    viewBox,
    zoomAt,
} from '../../assets/lib/pan_zoom.js';

const stage = { width: 1000, height: 500 };

it('fits a large diagram into the stage and centres it', () => {
    const state = fit({ x: 0, y: 0, width: 2000, height: 500 }, stage);
    expect(state.scale).toBe(0.5);
    expect(viewBox(state, stage)).toBe('0 -250 2000 1000');
});

it('fits a small diagram at 200% at most', () => {
    const state = fit({ x: 4, y: 4, width: 100, height: 50 }, stage);
    expect(state.scale).toBe(2);
    expect(state.x).toBe(4 + 50 - 250);
    expect(state.y).toBe(4 + 25 - 125);
});

it('clamps a scale to the limits', () => {
    expect(clampScale(10, 0.25, 4)).toBe(4);
    expect(clampScale(0.01, 0.25, 4)).toBe(0.25);
    expect(clampScale(1, 0.25, 4)).toBe(1);
});

it('lowers the minimum to the opening fit when that is below 25%', () => {
    expect(limits(0.18)).toEqual({ min: 0.18, max: 4 });
    expect(limits(0.6)).toEqual({ min: 0.25, max: 4 });
});

it('zooms around a point and keeps the diagram point under it', () => {
    const state = { scale: 1, x: 100, y: 50 };
    const point = { x: 200, y: 100 };
    const next = zoomAt(state, 2, point, { min: 0.25, max: 4 });
    expect(next.scale).toBe(2);
    expect(state.x + point.x / state.scale).toBe(next.x + point.x / next.scale);
    expect(state.y + point.y / state.scale).toBe(next.y + point.y / next.scale);
});

it('stops zooming at the limits', () => {
    const range = { min: 0.25, max: 4 };
    expect(
        zoomAt({ scale: 3, x: 0, y: 0 }, 10, { x: 0, y: 0 }, range).scale,
    ).toBe(4);
    expect(
        zoomAt({ scale: 0.3, x: 0, y: 0 }, 0.1, { x: 0, y: 0 }, range).scale,
    ).toBe(0.25);
});

it('pans by stage pixels, scaled to diagram units', () => {
    const next = pan({ scale: 2, x: 10, y: 10 }, 40, -20);
    expect(next).toEqual({ scale: 2, x: -10, y: 20 });
});
