import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { JSDOM } from 'jsdom';
import { afterEach, describe, expect, it } from 'vitest';

const require = createRequire(import.meta.url);
const {
    findHtmlInSvg,
    manifest,
    pageMap,
    serializeDocument,
    storedLink,
} = require('../../plugins/loupe/skills/loupe-product-design/references/canvas-fidelity.js');

const SVG = 'http://www.w3.org/2000/svg';
const folders = [];

afterEach(() => {
    while (folders.length > 0) rmSync(folders.pop(), { recursive: true });
});

const canvas = (files, layout = null) => {
    const folder = mkdtempSync(join(tmpdir(), 'fidelity-'));
    folders.push(folder);
    for (const [name, body] of Object.entries(files)) {
        mkdirSync(dirname(join(folder, name)), { recursive: true });
        writeFileSync(join(folder, name), body);
    }
    if (layout)
        writeFileSync(
            join(folder, 'canvas.json'),
            JSON.stringify({ boards: layout }),
        );

    return folder;
};

describe('serializeDocument', () => {
    it('marks the chosen option of a select', () => {
        const { document } = new JSDOM(
            '<select><option>A</option><option>B</option></select>',
        ).window;
        document.querySelector('select').value = 'B';

        serializeDocument(document);

        const [first, second] = document.querySelectorAll('option');
        expect(first.hasAttribute('selected')).toBe(false);
        expect(second.hasAttribute('selected')).toBe(true);
    });

    it('unwraps sc-for and sc-if and keeps their children', () => {
        const { document } = new JSDOM(
            '<div><sc-for><sc-if><b>x</b></sc-if><i>y</i></sc-for></div>',
        ).window;

        serializeDocument(document);

        expect(document.body.innerHTML).toBe('<div><b>x</b><i>y</i></div>');
    });

    it('turns a span inside an svg into plain text', () => {
        const { document } = new JSDOM('<body></body>').window;
        const svg = document.createElementNS(SVG, 'svg');
        const text = document.createElementNS(SVG, 'text');
        const span = document.createElement('span');
        span.textContent = '42';
        text.append(span);
        svg.append(text);
        document.body.append(svg);

        expect(findHtmlInSvg(document)).toHaveLength(1);
        serializeDocument(document);

        expect(findHtmlInSvg(document)).toHaveLength(0);
        expect(document.querySelector('text').textContent).toBe('42');
        expect(document.body.innerHTML).toBe('<svg><text>42</text></svg>');
    });

    it('leaves HTML inside a foreignObject alone', () => {
        const { document } = new JSDOM('<body></body>').window;
        const svg = document.createElementNS(SVG, 'svg');
        const foreign = document.createElementNS(SVG, 'foreignObject');
        const span = document.createElement('span');
        foreign.append(span);
        svg.append(foreign);
        document.body.append(svg);

        expect(findHtmlInSvg(document)).toHaveLength(0);
    });
});

describe('manifest', () => {
    it('lists the path, size and hash of each file, sorted by path', () => {
        const folder = canvas({ 'b/two.css': 'bb', 'a.html': 'a' });

        expect(manifest(folder)).toEqual([
            {
                path: 'a.html',
                size: 1,
                sha256: createHash('sha256').update('a').digest('hex'),
            },
            {
                path: 'b/two.css',
                size: 2,
                sha256: createHash('sha256').update('bb').digest('hex'),
            },
        ]);
    });
});

describe('pageMap', () => {
    const files = {
        'Top.dc.html': '',
        'Other.dc.html': '',
        'site/Pricing.dc.html': '',
        'support.js': '',
    };
    const layout = {
        'Top.dc.html': { w: 1280, h: 860 },
        'site/Pricing.dc.html': { w: 390, h: 1000 },
    };

    it('follows pagePath: the index at the root, the others keep their folder', () => {
        const map = pageMap(canvas(files, layout), { index: 'Top' });

        expect(map.map((m) => [m.board, m.out])).toEqual([
            ['Other.dc.html', 'Other.html'],
            ['Top.dc.html', 'index.html'],
            ['site/Pricing.dc.html', 'site/Pricing.html'],
        ]);
    });

    it('reads the size from canvas.json and falls back to a default', () => {
        const map = pageMap(canvas(files, layout), { index: 'Top' });

        expect(
            map.find((m) => m.board === 'site/Pricing.dc.html'),
        ).toMatchObject({ w: 390, h: 1000 });
        expect(map.find((m) => m.board === 'Other.dc.html')).toMatchObject({
            w: 1280,
            h: 800,
        });
    });

    it('keeps the plain Top.html when another artboard is the index', () => {
        const map = pageMap(canvas(files, layout), { index: 'Other' });

        expect(map.find((m) => m.board === 'Top.dc.html').out).toBe('Top.html');
    });

    it('refuses a companion file at the output path and names both', () => {
        const folder = canvas(
            { ...files, 'site/Pricing.html': '<p>x</p>' },
            layout,
        );

        expect(() => pageMap(folder, { index: 'Top' })).toThrow(
            /site\/Pricing\.dc\.html.*site\/Pricing\.html/,
        );
    });

    it('never counts support.js or canvas.json as a collision', () => {
        expect(() =>
            pageMap(canvas({ 'Top.dc.html': '', 'support.js': '' }, {}), {}),
        ).not.toThrow();
    });

    it('keeps only the picked artboards', () => {
        const map = pageMap(canvas(files, layout), {
            index: 'Top',
            boards: ['Other'],
        });

        expect(map.map((m) => m.board)).toEqual(['Other.dc.html']);
    });
});

describe('storedLink', () => {
    it('replaces the last segment of the entry link with the page path', () => {
        expect(
            storedLink(
                'https://x.example/v/1/sig/index.html',
                'site/My Page.html',
            ),
        ).toBe('https://x.example/v/1/sig/site/My%20Page.html');
    });

    it('refuses a link that is only a path', () => {
        expect(() =>
            storedLink('/artifact-content/1/2/index.html', 'index.html'),
        ).toThrow(/\/artifact-content\/1\/2\/index\.html/);
    });
});
