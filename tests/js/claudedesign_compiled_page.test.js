/** @vitest-environment jsdom */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { afterEach, beforeEach, expect, it } from 'vitest';

const golden = readFileSync(
    'cli/internal/claudedesign/testdata/interactive.compiled.html',
    'utf8',
);

let dom;
let ticks;

const settle = () => new Promise((resolve) => queueMicrotask(resolve));

beforeEach(() => {
    ticks = [];
    dom = new JSDOM(golden, {
        runScripts: 'dangerously',
        beforeParse(window) {
            window.setInterval = (fn) => ticks.push(fn);
        },
    });
});

afterEach(() => {
    dom.window.close();
});

const $ = (selector) => dom.window.document.querySelector(selector);

it('shows the build-time page before it runs, and the same page after', () => {
    expect($('h1').textContent).toBe('Fruit <list> & more');
    expect($('#inc').textContent).toBe('Count: 0');
    expect(dom.window.document.querySelectorAll('li')).toHaveLength(3);
});

it('counts a click', async () => {
    $('#inc').dispatchEvent(
        new dom.window.MouseEvent('click', { bubbles: true }),
    );
    $('#inc').dispatchEvent(
        new dom.window.MouseEvent('click', { bubbles: true }),
    );
    await settle();
    expect($('#inc').textContent).toBe('Count: 2');
});

it('filters the list while the input keeps its node', async () => {
    const input = $('#q');
    input.value = 'an';
    input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    await settle();
    expect($('#q')).toBe(input);
    expect(
        [...dom.window.document.querySelectorAll('li button')].map(
            (b) => b.textContent,
        ),
    ).toEqual(['Banana']);
    expect($('#note').textContent).toBe('1 shown');
});

it('runs the handler of the clicked loop item', async () => {
    dom.window.document
        .querySelectorAll('li button')[1]
        .dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();
    const items = dom.window.document.querySelectorAll('li');
    expect(items[1].className).toBe('done');
    expect(items[0].className).toBe('todo');
});

it('swaps the branch of an if on a change', async () => {
    const box = $('#agree');
    box.checked = true;
    box.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    await settle();
    expect($('#status').textContent).toBe('Thanks');
    expect($('#agree').checked).toBe(true);
});

it('runs componentDidMount and its timer', async () => {
    ticks.forEach((fn) => {
        fn();
        fn();
        fn();
    });
    await settle();
    expect($('#tick').textContent).toBe('3');
});

it('keeps a canvas string that names the base class after a redraw', async () => {
    expect($('#kind').textContent).toBe('dclogic <!--');
    $('#inc').dispatchEvent(
        new dom.window.MouseEvent('click', { bubbles: true }),
    );
    await settle();
    expect($('#inc').textContent).toBe('Count: 1');
    expect($('#kind').textContent).toBe('dclogic <!--');
});
