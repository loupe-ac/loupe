/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);
        return () => {
            subscription.removed = true;
        };
    },
}));

const { default: InboxReplyDraftController } =
    await import('../../assets/controllers/inbox_reply_draft_controller.js');
const {
    default: InboxLiveController,
    DEBOUNCE_MILLISECONDS,
    RETRY_MILLISECONDS,
} = await import('../../assets/controllers/inbox_live_controller.js');

const ASK = '0199aaaa-0000-7000-8000-000000000001';

let application;
let responses;

function row(key, title) {
    return `<a class="lp-list-row" data-inbox-request="${key}" href="/inbox?ask=${key}">${title}</a>`;
}

const DRAFT_TEMPLATE =
    '<template data-inbox-reply-draft-template><div data-inbox-reply-draft data-controller="inbox-reply-draft"><ul data-inbox-reply-draft-target="choiceList"></ul><div data-inbox-reply-draft-target="noteList"></div><button type="button" data-action="inbox-reply-draft#discard">Discard draft</button></div></template>';

function item({ open = true, note = '', number = 1, actions = true } = {}) {
    const body = !open
        ? '<div class="lp-inbox-item__response" data-inbox-response>Closed by the agent.</div>'
        : actions
          ? `<div class="lp-inbox-item__actions"><form id="inbox_action_${number}" class="lp-inbox-thread__reply" data-form-draft-key-value="item-${number}"><fieldset><input type="radio" name="pick" id="pick_a" value="0"><label for="pick_a">CSV</label><input type="radio" name="pick" id="pick_b" value="1"><label for="pick_b">JSON</label></fieldset><textarea name="note">${note}</textarea><button type="submit">Send answer</button></form>${DRAFT_TEMPLATE}</div>`
          : '';

    return `<article data-inbox-item="${number}" data-inbox-item-open="${open}" class="lp-inbox-item"><h3 class="lp-inbox-item__title">Which column?</h3><div data-inbox-thread>${body}</div></article>`;
}

function view({
    rows = [row(ASK, 'Which column?')],
    open = true,
    notice = '',
    status = '',
    note = '',
    number = 1,
    actions = true,
} = {}) {
    return `<turbo-frame id="inbox-view"><div class="lp-inbox-rows">${rows.join('')}</div><div class="lp-inbox-request-detail">${status}${notice}<section class="lp-inbox-ask" data-inbox-ask-id="${ASK}" data-inbox-ask-open="${open}">${item({ open, note, number, actions })}</section></div></turbo-frame>`;
}

function page(html) {
    return `<!doctype html><html><body>${html}</body></html>`;
}

function answer(html, ok = true, status = ok ? 200 : 500) {
    responses.push({ ok, status, text: async () => page(html) });
}

async function mount(html = view()) {
    document.body.innerHTML = `<main><div data-controller="inbox-live">${html}</div></main>`;
    await vi.advanceTimersByTimeAsync(0);
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);

    return live.subscriptions[0];
}

function changed(change = {}) {
    return subscription().handler({
        type: 'inbox.changed',
        askIds: [],
        local: false,
        own: false,
        ...change,
    });
}

async function settle() {
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
}

function typeNote(text) {
    const note = document.querySelector('textarea');
    note.value = text;
}

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions = [];
    responses = [];
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => responses.shift() ?? { ok: false, status: 404 }),
    );
    application = Application.start();
    application.register('inbox-live', InboxLiveController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('listens for inbox changes and stops on disconnect', async () => {
    await mount();
    expect(subscription().types).toBe('inbox.changed');
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(subscription().removed).toBe(true);
});

it('ignores a change this page made', async () => {
    await mount();
    changed({ own: true });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
});

it('reads the view once for a burst of changes, with the frame and the watched ask', async () => {
    await mount();
    answer(view());
    changed();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS / 2);
    changed();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(new URL(url).searchParams.get('ask')).toBe(ASK);
    expect(options.headers).toMatchObject({
        'Turbo-Frame': 'inbox-view',
        'X-Loupe-Live-Refresh': '1',
        'X-Loupe-Inbox-Watching': ASK,
    });
});

it('shows a new ask in the list', async () => {
    await mount();
    answer(
        view({ rows: [row('new', 'A new ask'), row(ASK, 'Which column?')] }),
    );
    changed();
    await settle();
    expect(
        [...document.querySelectorAll('[data-inbox-request]')].map(
            (link) => link.dataset.inboxRequest,
        ),
    ).toEqual(['new', ASK]);
});

it('reads the view again after a reconnect', async () => {
    await mount();
    answer(view());
    subscription().options.onReconnect();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('shows the typed reply with a Discard button when the item closed', async () => {
    await mount();
    typeNote('Use the Next column');
    document.querySelector('#pick_b').checked = true;
    answer(
        view({
            open: false,
            notice: '<p class="lp-inbox-status" data-inbox-closed-notice="withdrawn">Withdrawn by the agent.</p>',
        }),
    );
    changed();
    await settle();

    const draft = document.querySelector(
        '[data-inbox-thread] [data-inbox-reply-draft]',
    );
    expect(draft).not.toBeNull();
    expect(draft.dataset.inboxReplyDraftChoicesValue).toBe('["JSON"]');
    expect(draft.dataset.inboxReplyDraftNotesValue).toBe(
        '["Use the Next column"]',
    );
    expect(draft.dataset.inboxReplyDraftKeyValue).toBe('item-1');
    expect(document.querySelector('form')).toBeNull();
    expect(document.querySelector('[data-inbox-closed-notice]')).not.toBeNull();
});

it('keeps the unsent reply through the next read', async () => {
    await mount();
    typeNote('Use the Next column');
    answer(view({ open: false }));
    changed();
    await settle();
    answer(view({ open: false }));
    changed();
    await settle();

    expect(document.querySelectorAll('[data-inbox-reply-draft]')).toHaveLength(
        1,
    );
});

it('removes the unsent reply when the reader discards it', async () => {
    application.register('inbox-reply-draft', InboxReplyDraftController);
    await mount();
    typeNote('Use the Next column');
    answer(view({ open: false }));
    changed();
    await settle();

    await vi.advanceTimersByTimeAsync(0);
    const draft = document.querySelector('[data-inbox-reply-draft]');
    expect(
        draft.querySelector('[data-inbox-reply-draft-target="noteList"]')
            .textContent,
    ).toBe('Use the Next column');
    draft.querySelector('button').click();
    expect(document.querySelector('[data-inbox-reply-draft]')).toBeNull();
});

it('keeps the typed note and leaves the form open when the item is still open', async () => {
    await mount();
    typeNote('Half an answer');
    document.querySelector('#pick_b').checked = true;
    answer(
        view({ rows: [row('new', 'A new ask'), row(ASK, 'Which column?')] }),
    );
    changed();
    await settle();

    const note = document.querySelector('textarea');
    expect(note.value).toBe('Half an answer');
    expect(document.querySelector('#pick_b').checked).toBe(true);
    expect(document.querySelector('[data-inbox-reply-draft]')).toBeNull();
});

it('drops the typed form when the new view shows another item in its place', async () => {
    await mount();
    typeNote('Meant for the first item');
    answer(view({ number: 2 }));
    changed();
    await settle();

    expect(document.querySelectorAll('form')).toHaveLength(1);
    expect(document.querySelector('form').id).toBe('inbox_action_2');
    expect(document.querySelector('textarea').value).toBe('');
});

it('drops the typed form when the item stays open and the new view has no actions', async () => {
    await mount();
    typeNote('Half an answer');
    answer(view({ actions: false }));
    changed();
    await settle();

    expect(document.querySelector('form')).toBeNull();
    expect(document.querySelector('.lp-inbox-item__actions')).toBeNull();
});

it('shows the closed item as read-only when nothing was typed', async () => {
    await mount();
    answer(view({ open: false }));
    changed();
    await settle();
    expect(document.querySelector('.lp-inbox-item__actions')).toBeNull();
    expect(document.querySelector('[data-inbox-reply-draft]')).toBeNull();
    expect(document.querySelector('[data-inbox-response]')).not.toBeNull();
});

it('keeps the status line of the last action while the view reloads', async () => {
    const status = '<p class="lp-inbox-status" data-inbox-status>Answered.</p>';
    await mount(view({ status }));
    answer(view());
    changed();
    await settle();
    expect(document.querySelector('[data-inbox-status]')?.textContent).toBe(
        'Answered.',
    );
});

it('reads the view again after a server error, and stops after the last wait', async () => {
    await mount();
    RETRY_MILLISECONDS.forEach(() => answer('', false));
    answer('', false);
    changed();
    await settle();
    for (const wait of RETRY_MILLISECONDS) {
        await vi.advanceTimersByTimeAsync(wait);
    }
    expect(fetch).toHaveBeenCalledTimes(RETRY_MILLISECONDS.length + 1);
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS.at(-1) * 2);
    expect(fetch).toHaveBeenCalledTimes(RETRY_MILLISECONDS.length + 1);
});

it('holds the read while a dialog is open and reads when it closes', async () => {
    await mount(`${view()}<dialog open></dialog>`);
    answer(view());
    changed();
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    document.querySelector('dialog').removeAttribute('open');
    document.querySelector('dialog').dispatchEvent(new Event('close'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetch).toHaveBeenCalledTimes(1);
});

function split(picked, html) {
    const back = picked
        ? '<a class="lp-inbox-back" href="/inbox">Back</a>'
        : '';

    return html.replace(
        '<div class="lp-inbox-rows">',
        `<div class="lp-inbox-split" data-inbox-picked="${picked}">${back}<div class="lp-inbox-rows">`,
    );
}

it('keeps a view with no pick in its address unpicked, so a phone keeps the list', async () => {
    await mount(split(false, view()));
    answer(
        split(
            true,
            view({
                rows: [row(ASK, 'Which column?'), row('next', 'A new ask')],
            }),
        ),
    );
    changed();
    await settle();

    expect(new URL(fetch.mock.calls[0][0]).searchParams.get('ask')).toBe(ASK);
    expect(document.querySelectorAll('.lp-list-row')).toHaveLength(2);
    expect(
        document.querySelector('[data-inbox-picked]').dataset.inboxPicked,
    ).toBe('false');
    expect(document.querySelector('.lp-inbox-back')).toBeNull();
});

it('keeps a view picked when its address names the ask', async () => {
    window.history.replaceState(null, '', `/inbox?ask=${ASK}`);
    try {
        await mount(split(true, view()));
        answer(split(true, view()));
        changed();
        await settle();

        expect(
            document.querySelector('[data-inbox-picked]').dataset.inboxPicked,
        ).toBe('true');
        expect(document.querySelector('.lp-inbox-back')).not.toBeNull();
    } finally {
        window.history.replaceState(null, '', '/');
    }
});
