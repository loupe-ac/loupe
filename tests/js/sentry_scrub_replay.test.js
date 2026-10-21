import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import {
    KEPT_ATTRIBUTES,
    scrubReplayEnvelope,
    scrubbingTransport,
} from '../../assets/lib/sentry_scrub.js';

const ORIGIN = 'https://app.test';

function recording(events, segment = { segment_id: 0 }) {
    const payload = `${JSON.stringify(segment)}\n${JSON.stringify(events)}`;

    return [
        {
            type: 'replay_recording',
            length: new TextEncoder().encode(payload).length,
        },
        payload,
    ];
}

function envelope(events, event = {}) {
    return [
        { event_id: 'abc' },
        [[{ type: 'replay_event' }, event], recording(events)],
    ];
}

function events(scrubbed) {
    return JSON.parse(scrubbed[1][1][1].split('\n')[1]);
}

function fullSnapshot(node) {
    return { type: 2, data: { node, initialOffset: { top: 0, left: 0 } } };
}

function element(tagName, attributes = {}, childNodes = []) {
    return { type: 2, tagName, attributes, childNodes, id: 1 };
}

describe('scrubReplayEnvelope', () => {
    it('cuts the urls of the replay event to the origin', () => {
        const scrubbed = scrubReplayEnvelope(
            envelope([], {
                type: 'replay_event',
                urls: [`${ORIGIN}/reset/SECRET?token=x`],
                request: {
                    url: `${ORIGIN}/reset/SECRET?token=x`,
                    headers: {
                        Referer: `${ORIGIN}/a/SECRET`,
                        'User-Agent': 'x',
                    },
                },
            }),
        );

        const event = scrubbed[1][0][1];
        expect(event.urls).toEqual([ORIGIN]);
        expect(JSON.stringify(event)).not.toContain('SECRET');
        expect(event.request.headers['User-Agent']).toBe('x');
    });

    it('keeps the layout attributes and removes every other attribute', () => {
        const scrubbed = scrubReplayEnvelope(
            envelope([
                fullSnapshot(
                    element('a', {
                        class: 'button',
                        href: `${ORIGIN}/cards/SECRET`,
                        id: 'secret-heading',
                        'data-x-value': '{"title":"SECRET"}',
                        'aria-label': 'SECRET',
                        title: 'SECRET',
                        value: 'SECRET',
                        name: '_token',
                        'aria-expanded': 'true',
                        rr_width: '10px',
                    }),
                ),
            ]),
        );

        expect(events(scrubbed)[0].data.node.attributes).toEqual({
            class: 'button',
            'aria-expanded': 'true',
            rr_width: '10px',
        });
        expect(KEPT_ATTRIBUTES.has('href')).toBe(false);
    });

    it('treats the stylesheet of a link as built and any other CSS as inline', () => {
        const css =
            ':root { --spacing: 0.25rem; --note: private; } .a { top: 1px; tab-size: 7; }';
        const scrubbed = scrubReplayEnvelope(
            envelope([
                fullSnapshot(
                    element('html', {}, [
                        element('link', { rel: null, _cssText: css }),
                        element('div', { _cssText: css }),
                        element('div', {
                            style: '--spacing: 0.25rem; top: 5px',
                        }),
                        element('style', {}, [
                            {
                                type: 3,
                                isStyle: true,
                                textContent: css,
                                id: 5,
                            },
                        ]),
                    ]),
                ),
            ]),
        );

        const [link, div, styled, style] =
            events(scrubbed)[0].data.node.childNodes;
        expect(link.attributes._cssText).toBe(
            ':root { --spacing: 0.25rem; --note: initial; } .a { top: 1px; tab-size: 7; }',
        );
        expect(div.attributes._cssText).toBe(':root { } .a { top: 1px; }');
        expect(styled.attributes.style).toBe('top: 5px;');
        expect(style.childNodes[0].textContent).toBe(
            ':root { } .a { top: 1px; }',
        );
    });

    it('scrubs the style of an attribute change', () => {
        const scrubbed = scrubReplayEnvelope(
            envelope([
                {
                    type: 3,
                    data: {
                        source: 0,
                        texts: [],
                        removes: [],
                        adds: [],
                        attributes: [
                            {
                                id: 3,
                                attributes: {
                                    href: '/SECRET',
                                    style: {
                                        top: '4px',
                                        '--n': '987654321',
                                        color: ['red', 'important'],
                                        width: false,
                                    },
                                },
                            },
                        ],
                    },
                },
            ]),
        );

        expect(events(scrubbed)[0].data.attributes[0].attributes).toEqual({
            style: { top: '4px', width: false },
        });
    });

    it('scrubs the rules of a stylesheet change', () => {
        const scrubbed = scrubReplayEnvelope(
            envelope([
                {
                    type: 3,
                    data: {
                        source: 8,
                        id: 8,
                        adds: [{ rule: '.a { content: "SECRET"; top: 1px }' }],
                    },
                },
                {
                    type: 3,
                    data: {
                        source: 13,
                        id: 8,
                        set: { property: 'top', value: '3px' },
                    },
                },
                {
                    type: 3,
                    data: {
                        source: 13,
                        id: 8,
                        set: { property: '--note', value: 'SECRET' },
                    },
                },
            ]),
        );

        const records = events(scrubbed);
        expect(records).toHaveLength(2);
        expect(records[0].data.adds[0].rule).toBe('.a { top: 1px; }');
        expect(records[1].data.set).toEqual({ property: 'top', value: '3px' });
    });

    it('cuts the page url and drops the breadcrumbs that hold urls', () => {
        const scrubbed = scrubReplayEnvelope(
            envelope([
                {
                    type: 4,
                    data: { href: `${ORIGIN}/reset/SECRET?token=x`, width: 1 },
                },
                {
                    type: 5,
                    data: {
                        tag: 'breadcrumb',
                        payload: {
                            category: 'navigation',
                            data: { from: '/a/SECRET', to: '/b/SECRET' },
                        },
                    },
                },
                {
                    type: 5,
                    data: {
                        tag: 'breadcrumb',
                        payload: {
                            category: 'ui.click',
                            message:
                                'body > button#go.button[aria-label="SECRET"]',
                            data: {
                                node: {
                                    tagName: 'button',
                                    attributes: { id: 'go', class: 'button' },
                                },
                            },
                        },
                    },
                },
                {
                    type: 5,
                    data: {
                        tag: 'performanceSpan',
                        payload: {
                            op: 'resource.img',
                            description: `${ORIGIN}/SECRET/pic.png`,
                        },
                    },
                },
            ]),
        );

        const records = events(scrubbed);
        expect(records).toHaveLength(3);
        expect(records[0].data.href).toBe(ORIGIN);
        expect(records[1].data.payload.message).toBe('body > button.button');
        expect(records[1].data.payload.data.node.attributes).toEqual({
            class: 'button',
        });
        expect(records[2].data.payload.description).toBe(ORIGIN);
        expect(JSON.stringify(records)).not.toContain('SECRET');
    });

    it('sets the length header to the byte length of the new payload', () => {
        const scrubbed = scrubReplayEnvelope(
            envelope([
                fullSnapshot(element('p', { class: 'é', href: '/SECRET' })),
            ]),
        );

        const [header, payload] = scrubbed[1][1];
        expect(header.length).toBe(new TextEncoder().encode(payload).length);
        expect(payload.startsWith('{"segment_id":0}\n')).toBe(true);
        expect(payload).toContain('é');
    });

    it('leaves the other items alone', () => {
        const error = [{ type: 'event' }, { message: 'See /reset/SECRET' }];
        const scrubbed = scrubReplayEnvelope([{}, [error, ...envelope([])[1]]]);

        expect(scrubbed[1][0]).toEqual(error);
    });

    it('throws on a compressed recording, which it cannot read', () => {
        expect(() =>
            scrubReplayEnvelope([
                {},
                [[{ type: 'replay_recording', length: 3 }, new Uint8Array(3)]],
            ]),
        ).toThrow();
    });
});

describe('scrubbingTransport', () => {
    const request = () =>
        envelope([fullSnapshot(element('a', { href: '/S' }))]);

    function inner() {
        return {
            send: vi.fn(() => Promise.resolve({ statusCode: 200 })),
            flush: vi.fn(() => Promise.resolve(true)),
        };
    }

    it('sends the scrubbed replay', async () => {
        const transport = inner();
        const result = await scrubbingTransport(transport).send(request());

        expect(result).toEqual({ statusCode: 200 });
        const sent = transport.send.mock.calls[0][0];
        expect(events(sent)[0].data.node.attributes).toEqual({});
    });

    it('passes an envelope with no replay through as it is', async () => {
        const transport = inner();
        const plain = [{}, [[{ type: 'event' }, { message: 'x' }]]];
        await scrubbingTransport(transport).send(plain);

        expect(transport.send).toHaveBeenCalledWith(plain);
    });

    it('drops the replay items and keeps the rest when the scrub fails', async () => {
        const transport = inner();
        const error = [{ type: 'event' }, { message: 'x' }];
        const bad = [
            {},
            [
                error,
                [{ type: 'replay_event' }, {}],
                [{ type: 'replay_recording', length: 3 }, new Uint8Array(3)],
            ],
        ];
        await scrubbingTransport(transport).send(bad);

        expect(transport.send).toHaveBeenCalledOnce();
        expect(transport.send.mock.calls[0][0][1]).toEqual([error]);
    });

    it('sends nothing when the scrub fails and only replay items remain', async () => {
        const transport = inner();
        const bad = [
            {},
            [[{ type: 'replay_recording', length: 3 }, new Uint8Array(3)]],
        ];

        await expect(scrubbingTransport(transport).send(bad)).resolves.toEqual(
            {},
        );
        expect(transport.send).not.toHaveBeenCalled();
    });

    it('flushes the transport it wraps', async () => {
        const transport = inner();

        await expect(scrubbingTransport(transport).flush(50)).resolves.toBe(
            true,
        );
        expect(transport.flush).toHaveBeenCalledWith(50);
    });
});

describe('a replay recorded by SDK 11.4.0 in Chromium', () => {
    const { origin, markers } = JSON.parse(
        readFileSync('tests/js/fixtures/sentry_replay_markers.json', 'utf8'),
    );
    const raw = readFileSync(
        'tests/js/fixtures/sentry_replay_envelope.txt',
        'utf8',
    );
    const lines = raw.split('\n');
    const recorded = () => [
        JSON.parse(lines[0]),
        [
            [JSON.parse(lines[1]), JSON.parse(lines[2])],
            [JSON.parse(lines[3]), `${lines[4]}\n${lines[5]}`],
        ],
    ];
    const present = Object.entries(markers).filter(
        ([name]) => !['text', 'title'].includes(name),
    );

    it.each(present)('holds the %s marker before the scrub', (name, marker) => {
        expect(raw).toContain(marker);
    });

    it('holds no marker after the scrub', () => {
        const scrubbed = JSON.stringify(scrubReplayEnvelope(recorded()));

        for (const marker of Object.values(markers)) {
            expect(scrubbed).not.toContain(marker);
        }
        expect(scrubbed).not.toContain('?token=');
    });

    it('still draws the layout', () => {
        const scrubbed = scrubReplayEnvelope(recorded());
        const text = scrubbed[1][1][1];
        const snapshot = events(scrubbed).find(({ type }) => type === 2);
        const html = JSON.stringify(snapshot);

        expect(scrubbed[1][0][1].urls[0]).toBe(origin);
        expect(html).toContain('--spacing: 0.25rem');
        expect(html).toContain('--accent: #d4e94c');
        expect(html).toContain('top: 120px');
        expect(html).toContain('"class":"styled"');
        expect(html).toContain('"class":"button"');
        expect(scrubbed[1][1][0].length).toBe(
            new TextEncoder().encode(text).length,
        );
    });
});
