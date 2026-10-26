/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));
const turbo = vi.hoisted(() => ({
    renderStreamMessage: vi.fn(),
    morphBodyElements: vi.fn(),
}));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);
        return () => {
            subscription.removed = true;
        };
    },
}));
vi.mock('@hotwired/turbo', () => turbo);

const {
    default: ReviewDraftLiveController,
    DEBOUNCE_MILLISECONDS,
    RETRY_MILLISECONDS,
} = await import('../../assets/controllers/review_draft_live_controller.js');

const URL_PATH = '/projects/p/documents/d/review/draft-state';
const STOPPED =
    'Live updates stopped. Reload the page to see the current state.';

let application;
let responses;

const WARNING_AGENT =
    '<div id="draft-review-warning"><p>An agent still reviews this draft, round 2 of 6.</p></div>';
const WARNING_PLAIN =
    '<div id="draft-review-warning"><p>This document is still a draft.</p></div>';

function streamOf(warning, active) {
    return `<turbo-stream action="replace" target="draft-band"><template><section id="draft-band">Band</section></template></turbo-stream><turbo-stream action="replace" target="draft-review-warning" data-stop-confirm-active="${active}"><template>${warning}</template></turbo-stream>`;
}

function answer(status, body = '') {
    responses.push({
        ok: status >= 200 && status < 300,
        status,
        text: async () => body,
    });
}

async function mount() {
    document.body.innerHTML = `
    <div class="lp-review" data-controller="review-draft-live" data-review-draft-live-url-value="${URL_PATH}" data-review-draft-live-stopped-value="${STOPPED}">
        <section id="draft-band">Old band</section>
        <p role="status" data-review-draft-live-status></p>
        <dialog id="finish">
            <form data-controller="review-stop-confirm" data-review-stop-confirm-active-value="true">
                <p role="status" data-review-draft-live-status></p>
                ${WARNING_AGENT}
                <input type="radio" name="verdict" value="approved">
                <textarea name="note"></textarea>
            </form>
        </dialog>
        <div id="publish-dialog-holder"><dialog id="publish"></dialog></div>
    </div>`;
    await vi.advanceTimersByTimeAsync(0);
}

function signal() {
    expect(live.subscriptions).toHaveLength(1);

    return live.subscriptions[0].handler({
        type: 'review.draft_state_changed',
        local: false,
        own: false,
    });
}

function lines() {
    return [...document.querySelectorAll('[data-review-draft-live-status]')];
}

async function settle() {
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
}

function open(dialogId) {
    const dialog = document.getElementById(dialogId);
    dialog.setAttribute('open', '');

    return dialog;
}

function close(dialog) {
    dialog.removeAttribute('open');
    dialog.dispatchEvent(new Event('close'));
}

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions = [];
    responses = [];
    turbo.renderStreamMessage.mockReset();
    turbo.morphBodyElements.mockReset();
    turbo.renderStreamMessage.mockImplementation((html) => {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        parsed.querySelectorAll('turbo-stream').forEach((stream) => {
            const target = document.getElementById(
                stream.getAttribute('target'),
            );
            target.replaceWith(
                stream.querySelector('template').content.cloneNode(true),
            );
        });
    });
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => responses.shift() ?? { ok: false, status: 500 }),
    );
    application = Application.start();
    application.register('review-draft-live', ReviewDraftLiveController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('a signal', () => {
    it('listens for the draft state signal', async () => {
        await mount();
        expect(live.subscriptions[0].types).toBe('review.draft_state_changed');
    });

    it('marks the page while the hub is connected', async () => {
        await mount();
        const root = document.querySelector('.lp-review');
        expect(root.hasAttribute('data-review-draft-live-connected')).toBe(
            false,
        );
        live.subscriptions[0].options.onOpen();
        expect(root.hasAttribute('data-review-draft-live-connected')).toBe(
            true,
        );
        live.subscriptions[0].options.onError();
        expect(root.hasAttribute('data-review-draft-live-connected')).toBe(
            false,
        );
    });

    it('merges a burst into one fetch and renders the stream', async () => {
        answer(200, streamOf(WARNING_AGENT, 'true'));
        await mount();
        signal();
        signal();
        signal();
        await settle();
        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, options] = fetch.mock.calls[0];
        expect(url).toBe(URL_PATH);
        expect(options.headers.Accept).toBe('text/vnd.turbo-stream.html');
        expect(turbo.renderStreamMessage).toHaveBeenCalledTimes(1);
    });

    it('fetches again after a reconnect', async () => {
        answer(200, streamOf(WARNING_AGENT, 'true'));
        await mount();
        live.subscriptions[0].options.onReconnect();
        await settle();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('sets the second confirmation from the answer', async () => {
        answer(200, streamOf(WARNING_PLAIN, 'false'));
        await mount();
        signal();
        await settle();
        const form = document.querySelector('form');
        expect(form.dataset.reviewStopConfirmActiveValue).toBe('false');
    });

    it('keeps the verdict and the note of an open Finish review dialog', async () => {
        answer(200, streamOf(WARNING_PLAIN, 'false'));
        await mount();
        open('finish');
        document.querySelector('input[value="approved"]').checked = true;
        document.querySelector('textarea').value = 'My note';
        signal();
        await settle();
        expect(
            document.querySelector('#draft-review-warning').textContent,
        ).toContain('still a draft');
        expect(document.querySelector('input[value="approved"]').checked).toBe(
            true,
        );
        expect(document.querySelector('textarea').value).toBe('My note');
    });
});

describe('a dialog', () => {
    it('holds the update while the publish dialog is open, then applies it', async () => {
        answer(200, streamOf(WARNING_AGENT, 'true'));
        await mount();
        document.querySelector('#draft-band').innerHTML =
            '<dialog id="band-dialog"></dialog>';
        const dialog = open('band-dialog');
        signal();
        await settle();
        expect(fetch).not.toHaveBeenCalled();
        close(dialog);
        await settle();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('loads the page with a morph once the document is no longer a draft', async () => {
        answer(204);
        responses.push({
            ok: true,
            status: 200,
            text: async () =>
                '<!doctype html><html><body><p>In review</p></body></html>',
        });
        await mount();
        signal();
        await settle();
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(turbo.morphBodyElements).toHaveBeenCalledTimes(1);
        const [current, fresh] = turbo.morphBodyElements.mock.calls[0];
        expect(current).toBe(document.body);
        expect(fresh.textContent).toContain('In review');
    });

    it('holds the page load while any dialog is open', async () => {
        answer(204);
        answer(204);
        responses.push({
            ok: true,
            status: 200,
            text: async () => '<html><body>In review</body></html>',
        });
        await mount();
        const dialog = open('finish');
        signal();
        await settle();
        expect(turbo.morphBodyElements).not.toHaveBeenCalled();
        close(dialog);
        await settle();
        expect(turbo.morphBodyElements).toHaveBeenCalledTimes(1);
    });
});

describe('a published draft', () => {
    it('clears the warning and the second confirmation of an open Finish review dialog at once', async () => {
        answer(204);
        answer(204);
        responses.push({
            ok: true,
            status: 200,
            text: async () => '<html><body>In review</body></html>',
        });
        await mount();
        const dialog = open('finish');
        document.querySelector('input[value="approved"]').checked = true;
        document.querySelector('textarea').value = 'My note';
        signal();
        await settle();
        expect(
            document.querySelector('#draft-review-warning').textContent.trim(),
        ).toBe('');
        expect(
            document
                .querySelector('form')
                .getAttribute('data-review-stop-confirm-active-value'),
        ).toBe('false');
        expect(document.querySelector('input[value="approved"]').checked).toBe(
            true,
        );
        expect(document.querySelector('textarea').value).toBe('My note');
        expect(turbo.morphBodyElements).not.toHaveBeenCalled();
        close(dialog);
        await settle();
        expect(turbo.morphBodyElements).toHaveBeenCalledTimes(1);
    });
});

describe('a failed fetch', () => {
    it('retries with a growing wait, then says that live updates stopped', async () => {
        await mount();
        signal();
        await settle();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(lines().map((line) => line.textContent)).toEqual(['', '']);

        for (const wait of RETRY_MILLISECONDS) {
            await vi.advanceTimersByTimeAsync(wait - 1);
            expect(lines().map((line) => line.textContent)).toEqual(['', '']);
            await vi.advanceTimersByTimeAsync(1);
        }

        expect(fetch).toHaveBeenCalledTimes(1 + RETRY_MILLISECONDS.length);
        expect(lines().map((line) => line.textContent)).toEqual([
            STOPPED,
            STOPPED,
        ]);
        expect(
            lines().every((line) => line.getAttribute('role') === 'status'),
        ).toBe(true);
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledTimes(1 + RETRY_MILLISECONDS.length);
    });

    it('does not retry after a 403 or a 404, and says that live updates stopped', async () => {
        answer(403);
        await mount();
        signal();
        await settle();
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(lines().map((line) => line.textContent)).toEqual([
            STOPPED,
            STOPPED,
        ]);
    });

    it('does not retry after a 404', async () => {
        answer(404);
        await mount();
        signal();
        await settle();
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('clears the lines after a later fetch that succeeds', async () => {
        answer(403);
        await mount();
        signal();
        await settle();
        expect(lines()[0].textContent).toBe(STOPPED);

        answer(200, streamOf(WARNING_AGENT, 'true'));
        signal();
        await settle();
        expect(lines().map((line) => line.textContent)).toEqual(['', '']);
    });

    it('leaves the lines outside the ids that a stream replaces', async () => {
        answer(403);
        await mount();
        signal();
        await settle();
        for (const line of lines()) {
            expect(line.closest('#draft-band')).toBeNull();
            expect(line.closest('#draft-review-warning')).toBeNull();
        }
    });
});

describe('a page that is not a draft', () => {
    async function mountPlain() {
        document.body.innerHTML = `
    <div class="lp-review" data-controller="review-draft-live" data-review-draft-live-url-value="${URL_PATH}" data-review-draft-live-stopped-value="${STOPPED}">
        <p>Approved</p>
        <textarea name="comment"></textarea>
    </div>`;
        await vi.advanceTimersByTimeAsync(0);
    }

    it('loads the page with a morph when the draft state answers no content', async () => {
        answer(204);
        responses.push({
            ok: true,
            status: 200,
            text: async () =>
                '<!doctype html><html><body><p>Changes requested</p></body></html>',
        });
        await mountPlain();
        signal();
        await settle();
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(fetch.mock.calls[0][0]).toBe(URL_PATH);
        expect(turbo.morphBodyElements).toHaveBeenCalledTimes(1);
        const [current, fresh] = turbo.morphBodyElements.mock.calls[0];
        expect(current).toBe(document.body);
        expect(fresh.textContent).toContain('Changes requested');
        expect(turbo.renderStreamMessage).not.toHaveBeenCalled();
    });

    describe('the forms of the reader', () => {
        function morphEvent(current, incoming) {
            const event = new CustomEvent('turbo:before-morph-element', {
                bubbles: true,
                cancelable: true,
                detail: { currentElement: current, newElement: incoming },
            });
            current.dispatchEvent(event);

            return event;
        }

        async function morphWith(markup, ask) {
            answer(204);
            responses.push({
                ok: true,
                status: 200,
                text: async () => '<html><body><p>Approved</p></body></html>',
            });
            let outcome;
            turbo.morphBodyElements.mockImplementation(() => {
                outcome = ask();
            });
            document.body.innerHTML = `<div data-controller="review-draft-live" data-review-draft-live-url-value="${URL_PATH}" data-review-draft-live-stopped-value="${STOPPED}">${markup}</div>`;
            await vi.advanceTimersByTimeAsync(0);
            signal();
            await settle();

            return outcome;
        }

        const incoming = (hidden) => {
            const form = document.createElement('form');
            form.hidden = hidden;

            return form;
        };

        it('keeps a form with typed text out of the morph', async () => {
            const outcome = await morphWith(
                '<form id="composer"><textarea></textarea></form>',
                () => {
                    document.querySelector('textarea').value = 'Typed';

                    return morphEvent(
                        document.getElementById('composer'),
                        incoming(false),
                    ).defaultPrevented;
                },
            );
            expect(outcome).toBe(true);
        });

        it('keeps a form that a script opened out of the morph', async () => {
            const outcome = await morphWith(
                '<form id="composer"><textarea></textarea></form>',
                () =>
                    morphEvent(
                        document.getElementById('composer'),
                        incoming(true),
                    ).defaultPrevented,
            );
            expect(outcome).toBe(true);
        });

        it('lets a form with nothing typed and nothing opened morph', async () => {
            const outcome = await morphWith(
                '<form id="composer"><textarea></textarea></form>',
                () =>
                    morphEvent(
                        document.getElementById('composer'),
                        incoming(false),
                    ).defaultPrevented,
            );
            expect(outcome).toBe(false);
        });

        it('lets the morph remove a form the new page no longer has', async () => {
            const outcome = await morphWith(
                '<form id="composer"><textarea></textarea></form>',
                () => {
                    document.querySelector('textarea').value = 'Typed';

                    return morphEvent(
                        document.getElementById('composer'),
                        undefined,
                    ).defaultPrevented;
                },
            );
            expect(outcome).toBe(false);
        });

        it('listens for the morph only while it runs', async () => {
            await morphWith('<form id="composer"></form>', () => true);
            const form = document.createElement('form');
            form.innerHTML = '<textarea></textarea>';
            form.querySelector('textarea').value = 'Typed';
            document.body.append(form);

            expect(morphEvent(form, incoming(false)).defaultPrevented).toBe(
                false,
            );
        });
    });
});
