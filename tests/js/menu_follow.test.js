/** @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it } from 'vitest';
import { followMenuPanel } from '../../assets/lib/menu_placement.js';

let drawer;
let panel;
let drawerLeft;
let stopFollowing;

beforeEach(() => {
    document.body.innerHTML = `<aside id="drawer"><div id="anchor">
        <div id="panel" style="position: absolute"></div>
    </div></aside>`;
    drawer = document.getElementById('drawer');
    const anchor = document.getElementById('anchor');
    panel = document.getElementById('panel');
    drawerLeft = -200;
    anchor.getBoundingClientRect = () => ({ left: drawerLeft });
    Object.defineProperty(panel, 'offsetParent', { get: () => anchor });
    Object.defineProperty(panel, 'offsetLeft', { get: () => 0 });
    Object.defineProperty(panel, 'offsetWidth', { get: () => 100 });
    Object.defineProperty(document.documentElement, 'clientWidth', {
        configurable: true,
        get: () => 390,
    });
});

afterEach(() => {
    stopFollowing?.();
    stopFollowing = undefined;
});

function shift() {
    return panel.style.getPropertyValue('--lp-menu-shift');
}

it('places the panel again when a moving ancestor settles', () => {
    stopFollowing = followMenuPanel(panel);
    expect(shift()).not.toBe('');

    drawerLeft = 20;
    drawer.dispatchEvent(new Event('transitionend', { bubbles: true }));

    expect(shift()).toBe('');
});

it('ignores a transition that ends on the panel itself', () => {
    stopFollowing = followMenuPanel(panel);
    const placed = shift();

    drawerLeft = 20;
    panel.dispatchEvent(new Event('transitionend', { bubbles: true }));

    expect(shift()).toBe(placed);
});

it('stops placing the panel once the menu closes', () => {
    followMenuPanel(panel)();

    drawerLeft = 20;
    drawer.dispatchEvent(new Event('transitionend', { bubbles: true }));

    expect(shift()).not.toBe('');
});
