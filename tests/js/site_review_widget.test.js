/** @vitest-environment jsdom */
import { readFileSync } from 'node:fs';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import {
    BACKEND,
    bootWidget,
    hostCount,
    modeKeyFor,
    ok,
    openPanel,
    panelRoot,
    rejected,
    resetWidget,
    settle,
    storageKeyFor,
    ACCESS_TOKEN,
    PROJECT,
} from './support/widget_harness.js';

let originalHistory;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

afterEach(() => resetWidget(originalHistory));

/** The critical panel's detail line, which names the fix for this rejection. */
function fatalDetail() {
    openPanel();

    return panelRoot().getElementById('lp-fatal').querySelector('.lp-fatal-sub')
        .textContent;
}

/** Whether the collapsed launcher shows its rejected-token badge. */
function alerting() {
    return (
        panelRoot().getElementById('lp-launch-alert').style.display !== 'none'
    );
}

/** Opens the composer for a new page note, as the Note action does. */
function openNote() {
    const root = panelRoot();
    root.getElementById('lp-launch-main').click();
    root.getElementById('general').click();

    return root;
}

/** The view the panel shows now: list, compose, destination, finish or signin. */
const viewOf = (root) => root.getElementById('lp-views').dataset.view;

async function write(root, text) {
    const textarea = root.getElementById('lp-textarea');
    textarea.value = text;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

/** The body of the one feedback save the widget sent. */
function feedbackSave(fetchMock) {
    const save = fetchMock.mock.calls.find(
        ([url, init]) =>
            init &&
            init.method === 'POST' &&
            url.endsWith('/api/board/feedback'),
    );

    return save ? JSON.parse(save[1].body) : null;
}

const storedMode = () =>
    JSON.parse(window.localStorage.getItem(modeKeyFor()) || 'null');

const targetText = (root) =>
    root.getElementById('lp-context').querySelector('.lp-context-label')
        .textContent;

const CARD = '01a0cafe-0000-7000-8000-000000000001';

/** Draw mode marks the overlay, which lives in a shadow root of its own. */
const drawing = () =>
    [...document.documentElement.children]
        .filter((element) => element.shadowRoot)
        .some((element) => element.shadowRoot.querySelector('.lp-ov.drawing'));

/** Pick mode shows itself as a crosshair stylesheet on the page. */
const picking = () =>
    [...document.documentElement.children].some(
        (element) =>
            element.tagName === 'STYLE' &&
            element.textContent.includes('crosshair'),
    );

describe('the mode', () => {
    it('is asked for before the first note, and the draft is kept', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'The hero image is blurry');

        expect(viewOf(root)).toBe('destination');
        expect(root.getElementById('lp-save').disabled).toBe(true);
        root.querySelector('[data-mode="per-note"]').click();
        await settle();

        expect(storedMode()).toEqual({ mode: 'per-note', cardId: null });
        expect(viewOf(root)).not.toBe('destination');
        expect(root.getElementById('lp-textarea').value).toBe(
            'The hero image is blurry',
        );
        expect(fetchMock.mock.calls[0][0]).toBe(
            `${BACKEND}/api/site-review/review?scope=${encodeURIComponent('cards:')}`,
        );
    });

    it('comes before pick mode and draw mode', async () => {
        bootWidget({
            respond: () =>
                ok({ comments: [], context: null, drawingEnabled: true }),
        });
        await settle();
        const root = panelRoot();
        root.getElementById('lp-launch-main').click();

        for (const action of ['target', 'draw']) {
            root.getElementById(action).click();
            await settle();

            expect(viewOf(root)).toBe('destination');
            expect(picking()).toBe(false);
            expect(drawing()).toBe(false);
        }
    });

    /** Clicks Element and reports whether pick mode started, then leaves it. */
    async function pickStarts(root, { open = true } = {}) {
        if (open) root.getElementById('lp-launch-main').click();
        root.getElementById('target').click();
        await settle();
        const started = picking();
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        return started;
    }

    it('keeps a draft typed before the choice when picking starts', async () => {
        bootWidget({ respond: () => ok({ comments: [], context: null }) });
        await settle();
        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('target').click();
        await write(root, 'The footer links are grey on grey');
        root.querySelector('[data-mode="per-note"]').click();
        await settle();

        expect(await pickStarts(root, { open: false })).toBe(true);
        expect(root.getElementById('lp-textarea').value).toBe(
            'The footer links are grey on grey',
        );
    });

    it('is not asked again once it is stored', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        const root = panelRoot();

        expect(await pickStarts(root)).toBe(true);
        expect(viewOf(root)).not.toBe('destination');
    });

    it('is not asked on a preview page that names its card', async () => {
        bootWidget({
            context: `card:${CARD}`,
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#7 Preview card', url: null },
                }),
        });
        await settle();
        const root = panelRoot();

        expect(await pickStarts(root)).toBe(true);
        expect(viewOf(root)).not.toBe('destination');
    });

    it('per note sends a new card and names the card it became', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: CARD,
                          number: 12,
                          label: '#12 The hero image is blurry',
                          url: `${BACKEND}/projects/p/board/cards/${CARD}`,
                      })
                    : ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        expect(targetText(root)).toBe('A new card for each note');
        await write(root, 'The hero image is blurry');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ newCard: {} });
        const last = root.getElementById('lp-last-card');
        expect(last.textContent).toBe('Saved as #12 The hero image is blurry');
        expect(last.querySelector('a').href).toBe(
            `${BACKEND}/projects/p/board/cards/${CARD}`,
        );
    });

    it('per review is remembered, checked at boot and sent by card id', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: CARD,
                          number: 3,
                          label: '#3 Review: /',
                          url: null,
                      })
                    : ok({
                          comments: [],
                          context: { label: '#3 Review: /', url: null },
                      }),
        });
        await settle();

        expect(fetchMock.mock.calls[0][0]).toBe(
            `${BACKEND}/api/site-review/review?scope=${encodeURIComponent(`card:${CARD}`)}&context=${encodeURIComponent(`card:${CARD}`)}`,
        );
        const root = openNote();
        expect(targetText(root)).toBe('#3 Review: /');
        expect(viewOf(root)).not.toBe('destination');
        await write(root, 'Spacing is off');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ cardId: CARD });
        expect(root.getElementById('lp-last-card').style.display).toBe('none');
    });

    it('epic mode checks an epic and puts each note under it', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'epic', cardId: CARD },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: 'x',
                          number: 9,
                          label: '#9 Spacing',
                          url: null,
                      })
                    : ok({
                          comments: [],
                          context: { label: '#2 Launch review', url: null },
                      }),
        });
        await settle();

        expect(fetchMock.mock.calls[0][0]).toContain(
            encodeURIComponent(`epic:${CARD}`),
        );
        const root = openNote();
        expect(targetText(root)).toBe('Cards under #2 Launch review');
        await write(root, 'Spacing');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({
            newCard: { parentCardId: CARD },
        });
    });

    it('is asked for again when the boot load refuses the stored card', async () => {
        bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        expect(storedMode()).toBeNull();
        const root = openNote();
        expect(viewOf(root)).toBe('destination');
    });

    it('is asked for again, with the draft kept, when a save names a closed card', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#3 Review: /', url: null },
                }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Keep this text');
        fetchMock.mockImplementation(async () =>
            rejected(422, { error: 'target_closed' }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(storedMode()).toBeNull();
        expect(viewOf(root)).toBe('destination');
        expect(root.getElementById('lp-textarea').value).toBe('Keep this text');
        expect(root.getElementById('lp-error').textContent).toContain(
            'Choose where your notes go',
        );
    });
});

describe('the delivery id', () => {
    /** Every feedback save the widget sent, in order. */
    const feedbackSaves = (fetchMock) =>
        fetchMock.mock.calls
            .filter(
                ([url, init]) =>
                    init &&
                    init.method === 'POST' &&
                    url.endsWith('/api/board/feedback'),
            )
            .map(([, init]) => JSON.parse(init.body));

    it('is kept for a retry to the same target and renewed for another one', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#3 Review: /', url: null },
                }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Lost in transit');
        fetchMock.mockImplementation(async (url, init) =>
            init && init.method === 'POST'
                ? rejected(500, {})
                : ok({ comments: [], context: null }),
        );
        root.getElementById('lp-save').click();
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        root.getElementById('lp-context')
            .querySelector('.lp-context-label')
            .click();
        await settle();
        root.querySelector('[data-mode="per-note"]').click();
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        const saves = feedbackSaves(fetchMock);
        expect(saves.map((save) => save.target)).toEqual([
            { cardId: CARD },
            { cardId: CARD },
            { newCard: {} },
        ]);
        expect(saves[1].deliveryId).toBe(saves[0].deliveryId);
        expect(saves[2].deliveryId).not.toBe(saves[0].deliveryId);
    });
});

describe('the mode picker', () => {
    it('keeps focus in the visible view after Back and after a choice', async () => {
        bootWidget({ respond: () => ok({ comments: [], context: null }) });
        await settle();

        const root = openNote();
        const textarea = root.getElementById('lp-textarea');
        root.querySelector('[data-mode="per-review"]').click();
        await settle();
        expect(root.activeElement).toBe(
            root.getElementById('lp-picker-search'),
        );

        root.getElementById('lp-picker-back').click();
        await settle();
        expect(root.activeElement).toBe(
            root.querySelector('#lp-picker-modes [data-mode]'),
        );

        root.querySelector('[data-mode="per-note"]').click();
        await settle();
        expect(root.activeElement).toBe(textarea);
    });

    it('closes on Escape and keeps the draft', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Do not lose this');
        root.getElementById('lp-context').querySelector('button').click();
        await settle();
        root.querySelector('[data-mode="per-review"]').click();
        await settle();

        root.getElementById('lp-picker-search').dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                composed: true,
            }),
        );
        await settle();

        expect(viewOf(root)).not.toBe('destination');
        expect(viewOf(root)).toBe('compose');
        expect(root.getElementById('lp-textarea').value).toBe(
            'Do not lose this',
        );
        // Reopening starts from the modes, not from the card search.
        root.getElementById('lp-context').querySelector('button').click();
        await settle();
        expect(root.getElementById('lp-picker-modes').style.display).toBe('');
    });

    it('keeps a choice made while the boot load is still in flight', async () => {
        // The boot load asks about the stored card. A reviewer who picks
        // another mode before it answers owns the choice, so the answer about
        // the old card must neither clear nor relabel it.
        let answerBoot;
        bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                new Promise((resolve) => {
                    answerBoot = resolve;
                }),
        });
        await settle();

        const root = openNote();
        root.getElementById('lp-context').querySelector('button').click();
        await settle();
        root.querySelector('[data-mode="per-note"]').click();
        await settle();

        answerBoot(ok({ comments: [], context: null }));
        await settle();

        expect(storedMode()).toEqual({ mode: 'per-note', cardId: null });
        expect(targetText(root)).toBe('A new card for each note');
        expect(viewOf(root)).not.toBe('destination');
    });

    describe('the mode under a card that can have children', () => {
        const EPIC_ROW = '[data-mode="epic"]';

        /** Boots with the given grouping types and opens the picker in that mode. */
        async function openEpicPicker(parentTypes, calls = []) {
            const fetchMock = bootWidget({
                respond: (url, init) => {
                    calls.push([url, init]);

                    return init.method === 'POST'
                        ? ok({
                              cardId: 'c1',
                              number: 9,
                              label: '#9 Q4',
                              url: null,
                          })
                        : url.includes('/api/board/cards')
                          ? ok({ cards: [] })
                          : ok({ comments: [], context: null, parentTypes });
                },
            });
            await settle();
            const root = openNote();
            root.querySelector(EPIC_ROW).click();
            await settle();

            return { root, fetchMock };
        }

        const created = (fetchMock) =>
            JSON.parse(
                fetchMock.mock.calls.find(
                    ([url, init]) =>
                        url.endsWith('/api/board/cards') &&
                        init.method === 'POST',
                )[1].body,
            );

        it('hides the mode when no type can have children', async () => {
            bootWidget({
                respond: () =>
                    ok({ comments: [], context: null, parentTypes: [] }),
            });
            await settle();

            expect(openNote().querySelector(EPIC_ROW).style.display).toBe(
                'none',
            );
        });

        it('asks for the capability and shows no selector for one type', async () => {
            const { root, fetchMock } = await openEpicPicker([
                { key: 'epic', label: 'Epic' },
            ]);

            expect(
                fetchMock.mock.calls.some(([url]) =>
                    url.includes('/api/board/cards?parent=1'),
                ),
            ).toBe(true);
            expect(root.getElementById('lp-picker-type').style.display).toBe(
                'none',
            );
            root.getElementById('lp-picker-create').click();
            await settle();
            expect(created(fetchMock)).toEqual({
                title: expect.any(String),
                parent: true,
            });
        });

        it('sends the type the reviewer chose from the selector', async () => {
            const { root, fetchMock } = await openEpicPicker([
                { key: 'initiative', label: 'Initiative' },
                { key: 'epic', label: 'Epic' },
            ]);

            const select = root.getElementById('lp-picker-type');
            expect(select.style.display).toBe('');
            expect([...select.options].map((o) => o.textContent)).toEqual([
                'Initiative',
                'Epic',
            ]);
            expect(select.value).toBe('initiative');
            select.value = 'epic';
            root.getElementById('lp-picker-create').click();
            await settle();

            expect(created(fetchMock)).toMatchObject({
                parent: true,
                type: 'epic',
            });
        });
    });
});

describe('a stale copy of the widget', () => {
    it('is told to reload', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'From an old tab');
        fetchMock.mockImplementation(async () =>
            rejected(410, { error: 'widget_outdated' }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(root.getElementById('lp-error').textContent).toContain(
            'Reload the page to update the review widget.',
        );
    });
});

describe('a preview page that names its card', () => {
    it('sends every note to that card and shows no picker', async () => {
        const fetchMock = bootWidget({
            context: `card:${CARD}`,
            mode: { mode: 'per-note', cardId: null },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: CARD,
                          number: 7,
                          label: '#7 Preview',
                          url: null,
                      })
                    : ok({
                          comments: [],
                          context: { label: '#7 Preview', url: null },
                      }),
        });
        await settle();

        const root = openNote();
        expect(targetText(root)).toBe('#7 Preview');
        expect(
            root.getElementById('lp-context').querySelector('button'),
        ).toBeNull();
        expect(viewOf(root)).not.toBe('destination');
        await write(root, 'On the preview');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ cardId: CARD });
        expect(feedbackSave(fetchMock).context).toBe(`card:${CARD}`);
        // The lock belongs to this page. The reviewer's own choice stays.
        expect(storedMode()).toEqual({ mode: 'per-note', cardId: null });
    });

    it('says so, and refuses notes, when that card is closed or gone', async () => {
        bootWidget({
            context: `card:${CARD}`,
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();

        expect(targetText(root)).toContain('closed or gone');
        // A sentence the reviewer must read wraps rather than ellipsing.
        expect(
            root
                .getElementById('lp-context')
                .querySelector('.lp-context-label')
                .hasAttribute('data-wrap'),
        ).toBe(true);
        expect(viewOf(root)).not.toBe('destination');
        expect(root.getElementById('lp-save').disabled).toBe(true);
    });
});

describe('deleting a note', () => {
    it('goes through the feedback path, so an untouched card goes with it', async () => {
        const fetchMock = bootWidget({
            respond: (url, init) =>
                init.method === 'DELETE'
                    ? ok({ cardDeleted: true })
                    : ok({
                          comments: [
                              {
                                  id: 'c1',
                                  body: 'a note',
                                  url: 'about:blank',
                                  anchors: [],
                              },
                          ],
                          context: null,
                      }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('lp-clear').click();
        root.getElementById('lp-clear-yes').click();
        await settle();

        const deleted = fetchMock.mock.calls.find(
            ([, init]) => init && init.method === 'DELETE',
        );
        expect(deleted[0]).toBe(`${BACKEND}/api/board/feedback/c1`);
    });

    it('drops the confirm overlay of a row on Cancel with no animation event', async () => {
        bootWidget({
            respond: () =>
                ok({
                    comments: [
                        {
                            id: 'c1',
                            body: 'a note',
                            url: 'about:blank',
                            anchors: [],
                        },
                    ],
                    context: null,
                }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        const row = root.querySelector('#lp-list .lp-item');
        row.querySelector('.lp-del').click();
        row.querySelector('[data-del-no]').click();
        expect(row.querySelectorAll('.lp-item-confirm')).toHaveLength(1);

        await new Promise((resolve) => setTimeout(resolve, 350));
        expect(row.querySelectorAll('.lp-item-confirm')).toHaveLength(0);
    });
});

describe('a token revoked while the picker is open', () => {
    it('shows the critical panel rather than a stuck Loading', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await settle();

        fetchMock.mockImplementation(async () =>
            rejected(403, { error: 'token_not_bound_to_site' }),
        );
        root.querySelector('[data-mode="per-review"]').click();
        await settle();

        expect(root.getElementById('lp-fatal').style.display).not.toBe('none');
    });
});

describe('navigating to a page that names a different card', () => {
    /** Turbo swaps the tag after the URL changes, so the marker lands late. */
    async function navigate(to, marker) {
        const tag = document.querySelector(
            'script[src*="site-review/widget.js"]',
        );
        window.history.pushState({}, '', to);
        // The swap happens after pushState, which is the whole difficulty.
        if (marker === null) tag.removeAttribute('data-context');
        else tag.setAttribute('data-context', marker);
        await new Promise((resolve) => setTimeout(resolve, 300));
        await settle();
    }

    it('re-resolves against the new page marker', async () => {
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        await navigate('/second', 'card:second');

        const asked = fetchMock.mock.calls.map(([url]) => url);
        expect(asked.some((url) => url.includes('context=card%3Asecond'))).toBe(
            true,
        );
    });

    it('sends the new page card the moment the marker changes', async () => {
        // A reviewer who saves while the new page's card is still resolving
        // must not file against the page they have already left.
        let release;
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#1 First page card', url: null },
                }),
        });
        await settle();

        // The next answer never arrives, which is the window under test.
        fetchMock.mockImplementation(
            () => new Promise((resolve) => (release = resolve)),
        );
        await navigate('/second', 'card:second');
        expect(release).toBeTypeOf('function');

        const root = openNote();
        await write(root, 'Saved while the new page was still resolving');
        fetchMock.mockImplementation(async () =>
            ok({
                commentId: 'c9',
                cardId: 'second',
                number: 2,
                label: '#2',
                url: null,
            }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ cardId: 'second' });
    });

    it('drops the lock when the new page names no card', async () => {
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        fetchMock.mockClear();

        await navigate('/plain', null);

        const asked = fetchMock.mock.calls.map(([url]) => url);
        expect(asked.length).toBeGreaterThan(0);
        expect(asked.every((url) => !url.includes('context='))).toBe(true);
        expect(viewOf(openNote())).toBe('destination');
    });
});

describe('boot', () => {
    it('reads the backend from its own src and sends the stored grant', async () => {
        const fetchMock = bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe(
            `${BACKEND}/api/site-review/review?scope=${encodeURIComponent('cards:')}`,
        );
        expect(options.method).toBe('GET');
        expect(options.headers.Authorization).toBe(`Bearer ${ACCESS_TOKEN}`);
    });

    it('attaches its own shadow roots to the document element', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        expect(hostCount()).toBe(2);
        expect(panelRoot().getElementById('lp-launcher')).not.toBeNull();
    });

    it('runs once however often the script tag is executed', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        expect(hostCount()).toBe(2);
    });

    it('shows the comment count and no alert on a good load', async () => {
        bootWidget({
            respond: () =>
                ok({
                    comments: [
                        {
                            id: 1,
                            body: 'a note',
                            url: 'about:blank',
                            anchors: [],
                        },
                    ],
                }),
        });
        await settle();

        expect(alerting()).toBe(false);
        expect(panelRoot().getElementById('lp-launch-count').textContent).toBe(
            '1',
        );
    });
});

describe('a preview token', () => {
    const PREVIEW = 'preview_token_value';

    // Earlier tests leave a popup grant in the tab's session storage.
    beforeEach(() => window.sessionStorage.clear());

    it('signs the widget in when no popup grant exists', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [] }),
            signedIn: false,
            previewToken: PREVIEW,
        });
        await settle();

        const [, options] = fetchMock.mock.calls[0];
        expect(options.headers.Authorization).toBe(`Bearer ${PREVIEW}`);
        expect(alerting()).toBe(false);
    });

    it('yields to a popup grant', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [] }),
            previewToken: PREVIEW,
        });
        await settle();

        const [, options] = fetchMock.mock.calls[0];
        expect(options.headers.Authorization).toBe(`Bearer ${ACCESS_TOKEN}`);
    });

    it('shows the ended line and the sign-in button after a 401', async () => {
        const fetchMock = bootWidget({
            respond: () => rejected(401),
            signedIn: false,
            previewToken: PREVIEW,
        });
        await settle();
        await settle();
        openPanel();

        const root = panelRoot();
        expect(viewOf(root)).toBe('signin');
        expect(root.getElementById('lp-signin-note').textContent).toContain(
            'The preview sign-in for this page ended. Sign in, or restart the preview.',
        );
        expect(root.getElementById('lp-sign-in')).not.toBeNull();
        const used = fetchMock.mock.calls.filter(
            ([, options]) =>
                options.headers.Authorization === `Bearer ${PREVIEW}`,
        );
        expect(used).toHaveLength(1);
    });

    it('signs in again with the new token after a Turbo visit swaps it', async () => {
        const ROTATED = 'rotated_token_value';
        const fetchMock = bootWidget({
            respond: (url, init) =>
                init.headers.Authorization === `Bearer ${ROTATED}`
                    ? ok({ comments: [] })
                    : rejected(401),
            signedIn: false,
            previewToken: PREVIEW,
        });
        await settle();
        await settle();

        const tag = document.querySelector(
            'script[src*="site-review/widget.js"]',
        );
        window.history.pushState({}, '', '/next');
        // Turbo swaps the tag after the URL changes.
        tag.setAttribute('data-preview-token', ROTATED);
        await new Promise((resolve) => setTimeout(resolve, 300));
        await settle();
        openPanel();

        const sent = fetchMock.mock.calls.map(
            ([, options]) => options.headers.Authorization,
        );
        expect(sent).toEqual([`Bearer ${PREVIEW}`, `Bearer ${ROTATED}`]);
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'none',
        );
        expect(alerting()).toBe(false);
    });

    it('falls back to the page token when an expired grant cannot refresh', async () => {
        window.sessionStorage.setItem(
            storageKeyFor(PROJECT),
            JSON.stringify({
                accessToken: ACCESS_TOKEN,
                refreshToken: 'wt_test_refresh',
                expiresAt: Date.now() - 1000,
            }),
        );
        const fetchMock = bootWidget({
            respond: (url) =>
                url.endsWith('/oauth/token')
                    ? rejected(400, { error: 'invalid_grant' })
                    : ok({ comments: [] }),
            signedIn: false,
            previewToken: PREVIEW,
        });
        await settle();
        await settle();

        const api = fetchMock.mock.calls.filter(
            ([url]) => !url.endsWith('/oauth/token'),
        );
        expect(api[0][1].headers.Authorization).toBe(`Bearer ${PREVIEW}`);
        expect(alerting()).toBe(false);
    });

    it('falls back to the page token when a refused grant cannot refresh', async () => {
        const fetchMock = bootWidget({
            respond: (url, init) => {
                if (url.endsWith('/oauth/token'))
                    return rejected(400, { error: 'invalid_grant' });
                return init.headers.Authorization === `Bearer ${PREVIEW}`
                    ? ok({ comments: [] })
                    : rejected(401);
            },
            previewToken: PREVIEW,
        });
        await settle();
        await settle();

        const sent = fetchMock.mock.calls
            .filter(([url]) => !url.endsWith('/oauth/token'))
            .map(([, options]) => options.headers.Authorization);
        expect(sent).toEqual([`Bearer ${ACCESS_TOKEN}`, `Bearer ${PREVIEW}`]);
        expect(alerting()).toBe(false);
    });

    it('sends no token when the page carries none and no grant exists', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [] }),
            signedIn: false,
        });
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(alerting()).toBe(false);
        openPanel();
        expect(panelRoot().getElementById('lp-sign-in')).not.toBeNull();
    });
});

describe('a refused credential', () => {
    it('asks the reviewer to sign in again after a 401 it cannot refresh', async () => {
        bootWidget({ respond: () => rejected(401) });
        await settle();
        await settle();
        openPanel();

        expect(viewOf(panelRoot())).toBe('signin');
        expect(panelRoot().getElementById('lp-sign-in')).not.toBeNull();
    });

    it('names the missing scope for insufficient_scope', async () => {
        bootWidget({
            respond: () => rejected(403, { error: 'insufficient_scope' }),
        });
        await settle();

        expect(fatalDetail()).toContain('does not cover site review');
    });

    it('names the account for a 403 with no code', async () => {
        bootWidget({ respond: () => rejected(403) });
        await settle();

        expect(fatalDetail()).toContain('cannot review this project');
    });

    it('hides the comment list behind the critical panel', async () => {
        bootWidget({ respond: () => rejected(403) });
        await settle();
        openPanel();

        expect(panelRoot().getElementById('lp-views').style.display).toBe(
            'none',
        );
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'block',
        );
    });
});

describe('a transient failure', () => {
    it('leaves a 500 dismissible rather than fatal', async () => {
        bootWidget({ respond: () => rejected(500) });
        await settle();

        expect(alerting()).toBe(false);
        openPanel();
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'none',
        );
    });

    it('leaves a 429 dismissible rather than fatal', async () => {
        bootWidget({ respond: () => rejected(429, { error: 'too_many' }) });
        await settle();

        expect(alerting()).toBe(false);
    });
});

describe('the quote offer', () => {
    /** Selects the whole of a paragraph added under `wrapper`. */
    async function selectTextIn(wrapper) {
        const paragraph = document.createElement('p');
        paragraph.textContent = 'A sentence the reviewer selects.';
        wrapper.appendChild(paragraph);
        const range = document.createRange();
        range.selectNodeContents(paragraph.firstChild);
        const selection = document.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        document.dispatchEvent(new Event('selectionchange'));
        // The widget reads the selection in a frame, and jsdom runs those on a
        // 16ms timer that settle() does not reach.
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    /** The offer lives in the overlay root, not the panel's. */
    function quoteButton() {
        return [...document.documentElement.children]
            .filter((element) => element.shadowRoot)
            .map((element) => element.shadowRoot.getElementById('lp-quote-btn'))
            .find((element) => element != null);
    }

    it('is offered on an ordinary page', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();
        openPanel();
        await selectTextIn(document.body);

        expect(quoteButton().style.display).toBe('inline-flex');
    });

    it('survives an event that lands while pick mode owns the pointer', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [] }),
        });
        await settle();
        openPanel();
        await selectTextIn(document.body);
        panelRoot().getElementById('target').click();
        await settle();
        expect(quoteButton().style.display).toBe('none');

        // The drag's own trailing selectionchange lands here on a loaded
        // machine. Pick mode hides the offer and owes it back, so the event
        // must not take the pick away.
        document.dispatchEvent(new Event('selectionchange'));
        await new Promise((resolve) => setTimeout(resolve, 50));
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        expect(quoteButton().style.display).toBe('inline-flex');
    });

    it('stays away inside an element that opts out', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();
        openPanel();
        const wrapper = document.createElement('div');
        wrapper.setAttribute('data-site-review-quotes', 'off');
        document.body.appendChild(wrapper);
        await selectTextIn(wrapper);

        expect(quoteButton().style.display).toBe('none');
    });
});

describe('a stored selector that carries a stale class', () => {
    /** The chip that names how many of a comment's elements were found. */
    function chipText() {
        openPanel();

        return panelRoot().querySelector('.lp-chip').textContent;
    }

    function twoSections() {
        document.body.innerHTML =
            '<div class="panel">' +
            '<section class="row">first</section>' +
            '<section class="row">second</section>' +
            '</div>';
    }

    function withAnchors(anchors) {
        return {
            respond: () =>
                ok({
                    comments: [
                        {
                            id: 1,
                            body: 'a note',
                            url: location.href,
                            anchors,
                        },
                    ],
                }),
        };
    }

    it('still finds the element the classes no longer name', async () => {
        // `active` was on the section while the reviewer had it open. The page
        // drops it on the next visit, and the exact selector then misses an
        // element that is still there.
        twoSections();
        bootWidget(
            withAnchors([
                {
                    selector: 'div.panel > section.row.active:nth-of-type(1)',
                    text: 'first',
                },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('2 elements');
    });

    it('refuses a relaxed selector that names more than one element', async () => {
        // Dropping the classes here leaves `div.panel > section`, which both
        // sections answer. A guess between them would anchor the comment to
        // whichever came first.
        twoSections();
        bootWidget(
            withAnchors([
                { selector: 'div.panel > section.gone', text: 'first' },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('1 of 2 elements');
    });
    it('keeps a class that escapes a child combinator', async () => {
        // CSS.escape writes a Tailwind class such as [&>svg]:hidden with an
        // escaped `>`. Splitting the selector there would cut the class in two
        // and every relaxed candidate would be nonsense.
        document.body.innerHTML =
            '<div class="panel">' +
            '<section class="[&>svg]:hidden">first</section>' +
            '<section class="row">second</section>' +
            '</div>';
        bootWidget(
            withAnchors([
                {
                    selector:
                        'div.panel > section.\\[\\&\\>svg\\]\\:hidden.active:nth-of-type(1)',
                    text: 'first',
                },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('2 elements');
    });
    it('refuses a unique match that no longer reads like the target', async () => {
        // The anchored section is gone, and its :nth-of-type() seat now belongs
        // to a section that says something else. Uniqueness alone would draw
        // the comment on it.
        twoSections();
        bootWidget(
            withAnchors([
                {
                    selector: 'div.panel > section.row.active:nth-of-type(1)',
                    text: 'a heading that is no longer here',
                },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('1 of 2 elements');
    });
});

describe('style sheet colour tokens', () => {
    const source = readFileSync('public/site-review/widget.js', 'utf8');
    const palette = (name) => {
        const body = source.match(
            new RegExp(`const ${name} = \\{([\\s\\S]*?)\\n {4}\\};`),
        )[1];

        return [...body.matchAll(/'(--[a-z0-9-]+)'\s*:/g)].map(
            (match) => match[1],
        );
    };
    const used = [
        ...new Set(
            [...source.matchAll(/var\((--[a-z0-9-]+)/g)].map(
                (match) => match[1],
            ),
        ),
    ];

    it.each(['LIGHT', 'DARK'])(
        'uses only custom properties that the %s theme defines',
        (theme) => {
            const defined = new Set([...palette('CHROME'), ...palette(theme)]);

            expect(used.length).toBeGreaterThan(0);
            expect(defined.has('--text')).toBe(true);
            expect(used.filter((name) => !defined.has(name))).toEqual([]);
        },
    );
});

/** The scope and context of the nth list request the widget sent. */
const listRequest = (fetchMock, index = 0) => {
    const url = new URL(
        fetchMock.mock.calls.filter(([target]) =>
            target.includes('/api/site-review/review'),
        )[index][0],
    );

    return {
        scope: url.searchParams.get('scope'),
        context: url.searchParams.get('context'),
    };
};

const SESSION_CARDS_KEY = `loupe-site-review:session-cards:${BACKEND}:${PROJECT}`;

describe('the scope of the list request', () => {
    it('names the locked card, and still asks when that card is gone', async () => {
        const fetchMock = bootWidget({
            context: `card:${CARD}`,
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        expect(listRequest(fetchMock)).toEqual({
            scope: `card:${CARD}`,
            context: `card:${CARD}`,
        });
        // A card the server does not know reads as a broken lock.
        expect(openNote().getElementById('lp-sub').textContent).toContain(
            'closed or gone',
        );
    });

    it('names the card of a one-card review', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        expect(listRequest(fetchMock).scope).toBe(`card:${CARD}`);
    });

    it('names the epic of an epic review', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'epic', cardId: CARD },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        expect(listRequest(fetchMock).scope).toBe(`epic:${CARD}`);
    });

    it('lists the newest 50 cards of a tab in a per-note review', async () => {
        const ids = Array.from({ length: 60 }, (_, at) => `card-${at}`);
        window.sessionStorage.setItem(SESSION_CARDS_KEY, JSON.stringify(ids));
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        expect(listRequest(fetchMock)).toEqual({
            scope: `cards:${ids.slice(0, 50).join(',')}`,
            context: null,
        });
    });

    it('keeps the card of each save in session storage, never in local storage', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: 'made-1',
                          number: 3,
                          label: '#3 A note',
                          url: null,
                      })
                    : ok({ comments: [], context: null }),
        });
        await settle();
        const root = openNote();
        await write(root, 'A note');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock)).not.toBeNull();
        expect(
            JSON.parse(window.sessionStorage.getItem(SESSION_CARDS_KEY)),
        ).toEqual(['made-1']);
        expect(window.localStorage.getItem(SESSION_CARDS_KEY)).toBeNull();
    });

    it('forgets the cards of a tab when the destination changes', async () => {
        window.sessionStorage.setItem(
            SESSION_CARDS_KEY,
            JSON.stringify(['old-1']),
        );
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        const root = openNote();
        root.getElementById('lp-context').querySelector('button').click();
        root.querySelector('[data-mode="per-note"]').click();
        await settle();
        // The same destination keeps the list.
        expect(window.sessionStorage.getItem(SESSION_CARDS_KEY)).not.toBeNull();

        root.getElementById('lp-context').querySelector('button').click();
        root.querySelector('[data-mode="per-review"]').click();
        await settle();
        root.getElementById('lp-picker-title').value = 'Review: /';
        root.getElementById('lp-picker-create').click();
        await settle();

        expect(window.sessionStorage.getItem(SESSION_CARDS_KEY)).toBeNull();
        expect(fetchMock).toHaveBeenCalled();
    });
});

describe('the panel views', () => {
    const row = (id, body, url) => ({ id, body, url, anchors: [] });

    it('opens the destination over the note and returns to it', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        const root = openNote();
        expect(viewOf(root)).toBe('compose');

        root.getElementById('lp-context').querySelector('button').click();
        expect(viewOf(root)).toBe('destination');
        // Only the current view takes focus and the accessibility tree.
        expect(
            root.getElementById('lp-view-compose').hasAttribute('inert'),
        ).toBe(true);
        expect(
            root.getElementById('lp-view-destination').hasAttribute('inert'),
        ).toBe(false);

        root.getElementById('lp-destination-back').click();
        expect(viewOf(root)).toBe('compose');
        root.getElementById('lp-compose-back').click();
        expect(viewOf(root)).toBe('list');
    });

    it('groups the list by page, current page first, and numbers it so', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () =>
                ok({
                    comments: [
                        row('c1', 'Elsewhere', 'https://elsewhere.test/a'),
                        row('c2', 'Here', window.location.href),
                    ],
                    context: null,
                }),
        });
        await settle();
        openPanel();
        const root = panelRoot();

        const rows = [...root.querySelectorAll('#lp-list .lp-item')];
        expect(rows.map((node) => node.textContent)).toEqual([
            expect.stringContaining('Here'),
            expect.stringContaining('Elsewhere'),
        ]);
        expect(
            rows.map((node) => node.querySelector('.lp-badge').textContent),
        ).toEqual(['1', '2']);
        expect(
            [...root.querySelectorAll('#lp-list .lp-section')].map(
                (node) => node.textContent,
            ),
        ).toEqual(['On this page1', 'Other pages1']);
    });

    it('names the card and its pull request in the header of a lock', async () => {
        bootWidget({
            context: `card:${CARD}`,
            respond: (url) =>
                url.includes('/verdict')
                    ? ok({
                          connection: { state: 'connected' },
                          pullRequests: [
                              {
                                  id: 'p1',
                                  label: 'acme/site#88',
                                  ownPullRequest: false,
                              },
                          ],
                          notes: [],
                          preview: {},
                          latestVerdict: {
                              id: 'v1',
                              kind: 'request-changes',
                              message: '',
                              createdAt: '2026-10-08T10:00:00+00:00',
                              deliveries: [],
                          },
                      })
                    : ok({
                          comments: [],
                          context: {
                              label: '#412 Fix the redirect',
                              url: 'https://loupe.example/c',
                          },
                      }),
        });
        await settle();
        openPanel();
        const root = panelRoot();

        expect(root.getElementById('lp-title').textContent).toBe(
            '#412 Fix the redirect',
        );
        expect(root.getElementById('lp-sub').textContent).toBe(
            'PR #88 · Changes requested',
        );
        expect(root.getElementById('lp-finish').textContent).toBe(
            'Finish review',
        );
    });

    it('offers to open a card that has no pull request', async () => {
        bootWidget({
            context: `card:${CARD}`,
            respond: (url) =>
                url.includes('/verdict')
                    ? ok({
                          connection: { state: 'none' },
                          pullRequests: [],
                          notes: [],
                          preview: {},
                          latestVerdict: null,
                      })
                    : ok({
                          comments: [row('c1', 'A note', window.location.href)],
                          context: {
                              label: '#418 Group the board',
                              url: 'https://loupe.example/c',
                          },
                      }),
        });
        await settle();
        openPanel();
        const root = panelRoot();

        expect(root.getElementById('lp-sub').textContent).toBe(
            'No pull request yet · 1 note on this card',
        );
        expect(root.getElementById('lp-open-card').textContent).toContain(
            'Open #418 in Loupe',
        );
        expect(root.getElementById('lp-finish')).toBeNull();
    });

    it('offers to open the card of a one-card review', async () => {
        bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                ok({
                    comments: [],
                    context: {
                        label: '#420 Tidy the footer',
                        url: 'https://loupe.example/c420',
                    },
                }),
        });
        await settle();
        openPanel();
        const link = panelRoot().getElementById('lp-open-card');

        expect(link.textContent).toContain('Open #420 in Loupe');
        expect(link.getAttribute('href')).toBe('https://loupe.example/c420');
    });

    it('offers no card to open in a review that sends notes to many cards', async () => {
        bootWidget({
            mode: { mode: 'epic', cardId: CARD },
            respond: () =>
                ok({
                    comments: [],
                    context: {
                        label: '#421 The epic',
                        url: 'https://loupe.example/c421',
                    },
                }),
        });
        await settle();
        openPanel();

        expect(panelRoot().getElementById('lp-open-card')).toBeNull();
    });

    it('focuses the destination button when the destination closes over the list', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        openPanel();
        const root = panelRoot();
        const dest = root.getElementById('lp-dest');

        dest.click();
        expect(viewOf(root)).toBe('destination');
        root.getElementById('lp-picker-close').click();
        expect(viewOf(root)).toBe('list');
        expect(root.activeElement).toBe(dest);

        dest.click();
        root.getElementById('lp-destination-back').click();
        expect(root.activeElement).toBe(dest);
    });

    const escape = (node) =>
        node.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                composed: true,
            }),
        );

    it('focuses the destination button when Escape closes the destination over the list', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        openPanel();
        const root = panelRoot();
        const dest = root.getElementById('lp-dest');

        dest.click();
        const choice = root.querySelector('#lp-picker-modes [data-mode]');
        expect(root.activeElement).toBe(choice);
        escape(choice);
        expect(viewOf(root)).toBe('list');
        expect(root.activeElement).toBe(dest);
    });

    it('focuses Finish review when Back or Escape closes its view', async () => {
        bootWidget({
            context: `card:${CARD}`,
            respond: (url) =>
                url.includes('/verdict')
                    ? ok({
                          connection: { state: 'connected' },
                          pullRequests: [
                              {
                                  id: 'p1',
                                  label: 'acme/site#88',
                                  ownPullRequest: false,
                              },
                          ],
                          notes: [],
                          preview: {},
                          latestVerdict: null,
                      })
                    : ok({
                          comments: [],
                          context: {
                              label: '#412 Fix the redirect',
                              url: 'https://loupe.example/c',
                          },
                      }),
        });
        await settle();
        openPanel();
        const root = panelRoot();

        root.getElementById('lp-finish').click();
        await settle();
        expect(viewOf(root)).toBe('finish');
        root.getElementById('lp-finish-back').focus();
        root.getElementById('lp-finish-back').click();
        expect(viewOf(root)).toBe('list');
        expect(root.activeElement).toBe(root.getElementById('lp-finish'));

        root.getElementById('lp-finish').click();
        await settle();
        const back = root.getElementById('lp-finish-back');
        back.focus();
        escape(back);
        expect(viewOf(root)).toBe('list');
        expect(root.activeElement).toBe(root.getElementById('lp-finish'));
    });

    it('never leaves focus in a view that turned inert', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        const root = openNote();
        expect(root.activeElement).toBe(root.getElementById('lp-textarea'));

        root.getElementById('lp-compose-back').focus();
        root.getElementById('lp-compose-back').click();
        expect(viewOf(root)).toBe('list');
        expect(root.activeElement).not.toBeNull();
        expect(
            root.getElementById('lp-view-list').contains(root.activeElement),
        ).toBe(true);
    });

    it('shows the plain sign-in view with three steps', async () => {
        bootWidget({ signedIn: false, respond: () => ok({ comments: [] }) });
        await settle();
        openPanel();
        const root = panelRoot();

        expect(viewOf(root)).toBe('signin');
        expect(
            [...root.querySelectorAll('.lp-step')].map(
                (node) => node.textContent,
            ),
        ).toEqual([
            '1Point at anything on the page and write a note.',
            '2Each note saves at once to a card on your Loupe board.',
            '3Your agent picks the notes up and fixes them.',
        ]);
        expect(root.getElementById('lp-sign-in').textContent).toBe(
            'Sign in with Loupe',
        );
        expect(root.getElementById('lp-launch-label').textContent).toBe(
            'Sign in to review',
        );
    });
});
