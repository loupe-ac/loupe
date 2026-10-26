import { expect, it } from 'vitest';
import { menuShift } from '../../assets/lib/menu_placement.js';

it('leaves a panel that fits', () => {
    expect(menuShift(100, 200, 390, 16)).toBe(0);
});

it('moves a panel left that runs past the right edge', () => {
    expect(menuShift(150, 326, 390, 16)).toBe(-102);
});

it('moves a panel right that runs past the left edge', () => {
    expect(menuShift(-286, 326, 390, 16)).toBe(302);
});

it('keeps a panel as wide as the screen less the margin', () => {
    expect(menuShift(-5, 358, 390, 16)).toBe(21);
    expect(menuShift(16, 358, 390, 16)).toBe(0);
});
