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
    findTemplateInSvgText,
    manifest,
    pageMap,
    rebaseDocument,
    serializeDocument,
    serve,
    storedLink,
    verify,
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

describe('findTemplateInSvgText', () => {
    it('finds a value in the text of an SVG text, also in a branch the first render leaves out', () => {
        const source =
            '<sc-if value="{{isDonut}}"><svg viewBox="0 0 10 10"><path d="{{a.d}}"></path>' +
            '<text x="5" style="fill: red;">{{donutTotal}}</text><text><tspan>{{sub}} cards</tspan></text></svg></sc-if>';

        expect(findTemplateInSvgText(source)).toEqual([
            { tag: 'text', text: '{{donutTotal}}' },
            { tag: 'tspan', text: '{{sub}} cards' },
        ]);
    });

    it('ignores a value in an attribute, outside an SVG, or in a label laid over the chart', () => {
        const source =
            '<p>{{title}}</p><svg><text x="{{x}}">$1.00</text><path d="{{d}}"></path></svg>' +
            '<div style="position: absolute;">{{label}}</div>';

        expect(findTemplateInSvgText(source)).toEqual([]);
    });
});

describe('rebaseDocument', () => {
    const nested = () =>
        new JSDOM(
            '<head><link rel="stylesheet" href="ds/tokens.css">' +
                '<style>body { background: url("img/bg.png"); }</style></head>' +
                '<body><img src="./img/a.png" srcset="img/a.png 1x, img/b.png 2x">' +
                '<div style="background: url(img/c.png)"></div>' +
                '<a href="#top">t</a><a href="/root.css">r</a>' +
                '<a href="https://x.example/a">x</a><a href="img/d.png?v=1#f">d</a>' +
                '<a href="../../above.css">u</a></body>',
        ).window.document;

    it('keeps each link of a nested index artboard on its file when the page moves to the root', () => {
        const map = pageMap(
            canvas({ 'project/Main.dc.html': '', 'project/ds/tokens.css': '' }),
            {},
        );
        expect(map).toMatchObject([
            { board: 'project/Main.dc.html', out: 'index.html' },
        ]);
        const document = nested();

        rebaseDocument(document, 'project', '.');

        expect(document.querySelector('link').getAttribute('href')).toBe(
            'project/ds/tokens.css',
        );
        expect(document.querySelector('style').textContent).toBe(
            'body { background: url("project/img/bg.png"); }',
        );
        const img = document.querySelector('img');
        expect(img.getAttribute('src')).toBe('project/img/a.png');
        expect(img.getAttribute('srcset')).toBe(
            'project/img/a.png 1x, project/img/b.png 2x',
        );
        expect(document.querySelector('div').getAttribute('style')).toBe(
            'background: url(project/img/c.png)',
        );
        expect(
            Array.from(document.querySelectorAll('a')).map((a) =>
                a.getAttribute('href'),
            ),
        ).toEqual([
            '#top',
            '/root.css',
            'https://x.example/a',
            'project/img/d.png?v=1#f',
            '../../above.css',
        ]);
    });

    it('climbs out of a folder when the page moves down into it', () => {
        const document = nested();

        rebaseDocument(document, '.', 'site');

        expect(document.querySelector('link').getAttribute('href')).toBe(
            '../ds/tokens.css',
        );
    });

    it('changes nothing when the page stays in its folder', () => {
        const document = nested();
        const before = document.documentElement.outerHTML;

        rebaseDocument(document, 'site', 'site');

        expect(document.documentElement.outerHTML).toBe(before);
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

describe('verify', () => {
    const served = [];

    afterEach(() => {
        while (served.length > 0) served.pop().close();
    });

    const store = async (files) => {
        const { server, origin } = await serve(canvas(files));
        served.push(server);

        return `${origin}/index.html`;
    };

    it('passes when each stored file, binary included, has the expected hash', async () => {
        const font = Buffer.from([0, 1, 2, 255]);
        const link = await store({ 'index.html': 'a', 'ds/font.woff2': font });

        expect(
            await verify(
                canvas({ 'index.html': 'a', 'ds/font.woff2': font }),
                link,
            ),
        ).toEqual([]);
    });

    it('names a binary file of the same size and another hash, and a missing file', async () => {
        const link = await store({
            'index.html': 'a',
            'img/x.png': Buffer.from([1, 2, 3]),
        });

        const problems = await verify(
            canvas({
                'index.html': 'a',
                'img/x.png': Buffer.from([1, 2, 4]),
                'img/y.png': Buffer.from([9]),
            }),
            link,
        );

        expect(problems).toHaveLength(2);
        expect(problems[0]).toMatch(
            /^img\/x\.png: stored [0-9a-f]{64} 3, expected [0-9a-f]{64} 3$/,
        );
        expect(problems[1]).toBe('img/y.png: the stored version answered 404');
        expect(problems.join()).not.toContain(link);
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
