/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import ContentsRailController from '../../assets/controllers/contents_rail_controller.js';

let application;

const rows = `<a href="#one" data-contents-rail-target="link">One</a>
    <a href="#two" data-contents-rail-target="link">Two</a>`;

async function start(railRows) {
    document.body.innerHTML = `<h2 id="one">One</h2><h2 id="two">Two</h2>
        <aside data-controller="contents-rail">${railRows}</aside>`;
    application = Application.start();
    application.register('contents-rail', ContentsRailController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const links = () => [...document.querySelectorAll('a')];

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('marks the current section again after a morph resets the rows', async () => {
    await start(rows);
    const marked = links().filter((link) => link.hasAttribute('aria-current'));
    expect(marked).toHaveLength(1);
    for (const link of links()) {
        link.removeAttribute('aria-current');
        link.className = '';
    }
    document.dispatchEvent(new Event('turbo:morph'));
    expect(links().filter((link) => link.hasAttribute('aria-current'))).toEqual(
        marked,
    );
});

it('starts watching the headings when a morph brings in the first rows', async () => {
    await start('');
    document.querySelector('aside').innerHTML = rows;
    await new Promise((resolve) => setTimeout(resolve, 0));
    document.dispatchEvent(new Event('turbo:morph'));
    expect(
        links().filter((link) => link.hasAttribute('aria-current')),
    ).toHaveLength(1);
});
