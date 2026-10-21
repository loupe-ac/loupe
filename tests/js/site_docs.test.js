import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    fileURLToPath(
        new URL(
            '../../src/Module/Landing/Resources/site-docs.js',
            import.meta.url,
        ),
    ),
    'utf8',
);

function tree() {
    return { go: { hidden: true, href: '' }, out: { hidden: false } };
}

function rootOf(nodes) {
    return {
        querySelectorAll: (selector) =>
            selector.includes('go-to-app') ? [nodes.go] : [nodes.out],
    };
}

function run(cookie, config = {}) {
    const light = tree();
    const shadow = tree();
    const added = [];
    const fakeDocument = {
        cookie,
        readyState: 'complete',
        head: { appendChild: (element) => added.push(element) },
        createElement: () => ({
            setAttribute(name, value) {
                this[name] = value;
            },
        }),
        querySelectorAll: (selector) =>
            selector === '*'
                ? [{}, { shadowRoot: rootOf(shadow) }]
                : rootOf(light).querySelectorAll(selector),
    };
    const settings = {
        appOrigin: 'https://app.test',
        umami: null,
        widget: null,
        ...config,
    };
    new Function('document', 'loupeSiteDocs', source)(fakeDocument, settings);

    return { light, shadow, added };
}

describe('site docs script', () => {
    it('shows Go to app in the page and in a shadow root when signed in', () => {
        const { light, shadow } = run('x=1; loupe_signed_in=1');

        for (const nodes of [light, shadow]) {
            expect(nodes.go).toEqual({
                hidden: false,
                href: 'https://app.test/',
            });
            expect(nodes.out.hidden).toBe(true);
        }
    });

    it('keeps the signed-out bar for a visitor and for an unknown value', () => {
        for (const cookie of ['', 'loupe_signed_in=zz']) {
            const { light, shadow } = run(cookie);

            expect(light.go.hidden).toBe(true);
            expect(shadow.go.hidden).toBe(true);
            expect(shadow.out.hidden).toBe(false);
        }
    });

    it('adds the Umami tag for a visitor only', () => {
        const umami = { src: 'https://a.test/s.js', websiteId: 'wid' };

        expect(run('', { umami }).added.map((tag) => tag.src)).toEqual([
            'https://a.test/s.js',
        ]);
        expect(run('loupe_signed_in=1', { umami }).added).toEqual([]);
    });

    it('loads the widget for the admin value, or for everyone when public', () => {
        const widget = {
            project: 'p',
            backend: 'https://app.test',
            context: '',
            public: false,
        };
        const src = 'https://app.test/live-review/widget.js';

        expect(
            run('loupe_signed_in=admin', { widget }).added.map(
                (tag) => tag.src,
            ),
        ).toEqual([src]);
        expect(run('loupe_signed_in=1', { widget }).added).toEqual([]);
        expect(
            run('', { widget: { ...widget, public: true } }).added.map(
                (tag) => tag.src,
            ),
        ).toEqual([src]);
    });
});
