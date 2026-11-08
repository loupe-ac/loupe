import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';

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
        listeners: [],
        addEventListener(type, listener) {
            this.listeners.push({ type, listener });
        },
        querySelectorAll: (selector) =>
            selector.includes('go-to-app') ? [nodes.go] : [nodes.out],
    };
}

function run(cookie, config = {}) {
    const light = tree();
    const shadow = tree();
    const lightRoot = rootOf(light);
    const shadowRoot = rootOf(shadow);
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
                ? [{}, { shadowRoot }]
                : lightRoot.querySelectorAll(selector),
    };
    const settings = {
        appOrigin: 'https://app.test',
        umami: null,
        widget: null,
        ...config,
    };
    new Function('document', 'loupeSiteDocs', source)(fakeDocument, settings);

    return { light, shadow, added, shadowRoot, lightRoot };
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

describe('site docs script counting', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function link(attributes, extra = {}) {
        return {
            tagName: 'A',
            href: 'https://site.test/features',
            target: '',
            attributes: Object.entries(attributes).map(([name, value]) => ({
                name,
                value,
            })),
            getAttribute: (name) => attributes[name] ?? null,
            ...extra,
        };
    }

    function click(target, init = {}) {
        const event = {
            target: { closest: () => target },
            ctrlKey: false,
            shiftKey: false,
            metaKey: false,
            button: 0,
            preventDefault: vi.fn(),
            ...init,
        };
        const { shadowRoot } = run('');
        shadowRoot.listeners.forEach(({ listener }) => listener(event));

        return event;
    }

    const attributes = {
        'data-umami-event': 'feature-click',
        'data-umami-event-button': 'menu',
        'data-umami-event-feature': 'inbox',
    };

    it('listens in each shadow root and not on the page, where Umami listens', () => {
        const { shadowRoot, lightRoot } = run('');

        expect(shadowRoot.listeners.map((entry) => entry.type)).toEqual([
            'click',
        ]);
        expect(lightRoot.listeners).toEqual([]);
    });

    it('sends the event name and its data, then follows a link in the same tab', async () => {
        const track = vi.fn(() => Promise.resolve());
        const location = { href: '' };
        vi.stubGlobal('umami', { track });
        vi.stubGlobal('location', location);

        const event = click(link(attributes));

        expect(track).toHaveBeenCalledWith('feature-click', {
            button: 'menu',
            feature: 'inbox',
        });
        expect(event.preventDefault).toHaveBeenCalledTimes(1);
        expect(location.href).toBe('');
        await vi.waitFor(() =>
            expect(location.href).toBe('https://site.test/features'),
        );
    });

    it('holds the navigation until the call settles, and not for ever', async () => {
        vi.useFakeTimers();
        const location = { href: '' };
        vi.stubGlobal('umami', { track: () => new Promise(() => {}) });
        vi.stubGlobal('location', location);

        click(link(attributes));
        await vi.advanceTimersByTimeAsync(500);
        expect(location.href).toBe('');
        await vi.advanceTimersByTimeAsync(600);
        expect(location.href).toBe('https://site.test/features');
        vi.useRealTimers();
    });

    it('leaves a new tab, a modified click and a failed call to the browser', async () => {
        const track = vi.fn(() => Promise.reject(new Error('blocked')));
        const location = { href: '' };
        vi.stubGlobal('umami', { track });
        vi.stubGlobal('location', location);

        const newTab = click(link(attributes, { target: '_blank' }));
        const modified = click(link(attributes), { ctrlKey: true });

        expect(track).toHaveBeenCalledTimes(2);
        expect(newTab.preventDefault).not.toHaveBeenCalled();
        expect(modified.preventDefault).not.toHaveBeenCalled();
        expect(location.href).toBe('');

        click(link(attributes));
        await vi.waitFor(() =>
            expect(location.href).toBe('https://site.test/features'),
        );
    });

    it('does nothing for a click on an element with no event, or when Umami is not loaded', () => {
        const track = vi.fn();
        vi.stubGlobal('umami', { track });
        const plain = click(null);
        expect(track).not.toHaveBeenCalled();
        expect(plain.preventDefault).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
        const event = click(link(attributes));
        expect(event.preventDefault).not.toHaveBeenCalled();
    });
});
