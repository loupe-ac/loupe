/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    HOLD_RECHECK_MILLISECONDS,
    LiveFetcher,
    MAX_WAIT_MILLISECONDS,
    QUIET_MILLISECONDS,
    RETRY_MILLISECONDS,
    handleLostAccess,
    hasTypedText,
    isHeld,
    isLost,
    liveRequest,
    onLostAccess,
    reset,
} from '../../assets/lib/live_fetch.js';

function response(status, headers = {}) {
    return {
        status,
        ok: status >= 200 && status < 300,
        headers: { get: (name) => headers[name] ?? null },
        text: async () => '',
    };
}

function lostResponse(status, cause) {
    return response(status, { 'X-Loupe-Live-Lost': cause });
}

function band(name, text) {
    return `<script type="text/html" data-live-band-template="${name}"><div class="lp-flash">${text}</div></script>`;
}

function page() {
    document.body.innerHTML = `${band('stopped', 'Stopped')}${band('signed-out', 'Signed out')}${band('project-deleted', 'Deleted')}${band('generic', 'Generic')}<main id="main-content"><section id="a" data-live-content>A</section><section id="b" data-live-content>B</section><div id="region"><input id="field" value="x"></div></main>`;
}

beforeEach(() => {
    vi.useFakeTimers();
    reset();
    page();
});

afterEach(() => {
    vi.useRealTimers();
    document.body.replaceChildren();
});

describe('handleLostAccess', () => {
    it('ignores an answer that is not a lost-access answer', () => {
        expect(handleLostAccess(response(200))).toBe(false);
        expect(handleLostAccess(response(401))).toBe(false);
        expect(
            handleLostAccess(response(404, { 'X-Loupe-Live-Lost': 'x' })),
        ).toBe(false);
        expect(handleLostAccess(undefined)).toBe(false);
        expect(isLost()).toBe(false);
    });

    it('hides each content element and shows one band before the first', () => {
        expect(handleLostAccess(lostResponse(401, 'signed-out'))).toBe(true);
        expect(document.querySelector('#a').hidden).toBe(true);
        expect(document.querySelector('#b').hidden).toBe(true);
        const bands = document.querySelectorAll('[data-live-lost-band]');
        expect(bands).toHaveLength(1);
        expect(bands[0].textContent).toBe('Signed out');
        expect(bands[0].nextElementSibling.id).toBe('a');
        expect(isLost()).toBe(true);
    });

    it('shows one band however often it runs', () => {
        handleLostAccess(lostResponse(410, 'project-deleted'));
        handleLostAccess(lostResponse(401, 'signed-out'));
        const bands = document.querySelectorAll('[data-live-lost-band]');
        expect(bands).toHaveLength(1);
        expect(bands[0].textContent).toBe('Deleted');
    });

    it('uses the generic band for an unknown cause', () => {
        handleLostAccess(lostResponse(410, 'something-new'));
        expect(
            document.querySelector('[data-live-lost-band]').textContent,
        ).toBe('Generic');
    });

    it('puts the band in the main area when no content is marked', () => {
        document
            .querySelectorAll('[data-live-content]')
            .forEach((element) => element.removeAttribute('data-live-content'));
        handleLostAccess(lostResponse(401, 'signed-out'));
        expect(
            document.querySelector('main').firstElementChild.textContent,
        ).toBe('Signed out');
    });

    it('tells the listeners once', () => {
        const listener = vi.fn();
        const remove = onLostAccess(listener);
        handleLostAccess(lostResponse(401, 'signed-out'));
        handleLostAccess(lostResponse(401, 'signed-out'));
        expect(listener).toHaveBeenCalledTimes(1);
        expect(listener).toHaveBeenCalledWith('signed-out');
        remove();
    });

    it('forgets the loss on the next Turbo load', () => {
        handleLostAccess(lostResponse(401, 'signed-out'));
        document.dispatchEvent(new Event('turbo:load'));
        expect(isLost()).toBe(false);
    });
});

describe('the hold', () => {
    it('holds while a drag runs', () => {
        const root = document.querySelector('#region');
        root.querySelector('input').value = 'x';
        expect(isHeld(root)).toBe(false);
        document.querySelector('#a').dataset.dragging = '';
        expect(isHeld(root)).toBe(true);
    });

    it('holds while a dialog is open', () => {
        const dialog = document.createElement('dialog');
        dialog.setAttribute('open', '');
        document.body.append(dialog);
        expect(isHeld(document.querySelector('#region'))).toBe(true);
    });

    it('holds for typed text only', () => {
        const root = document.querySelector('#region');
        expect(hasTypedText(root)).toBe(false);
        root.querySelector('input').value = 'typed';
        expect(hasTypedText(root)).toBe(true);
        root.innerHTML =
            '<input type="checkbox" checked><input type="hidden" value="a"><textarea>seed</textarea>';
        expect(hasTypedText(root)).toBe(false);
        root.querySelector('textarea').value = 'changed';
        expect(hasTypedText(root)).toBe(true);
    });
});

describe('liveRequest', () => {
    it('sends the refresh header and the session', async () => {
        const fetchMock = vi.fn(async () => response(200));
        vi.stubGlobal('fetch', fetchMock);
        await liveRequest('/x', { headers: { Accept: 'text/foo' } });
        expect(fetchMock.mock.calls[0][1]).toMatchObject({
            headers: { Accept: 'text/foo', 'X-Loupe-Live-Refresh': '1' },
            credentials: 'same-origin',
        });
        vi.unstubAllGlobals();
    });
});

describe('LiveFetcher', () => {
    let load;
    let apply;
    let onStopped;
    let fetcher;

    function make(options = {}) {
        fetcher = new LiveFetcher({
            root: document.querySelector('#region'),
            load,
            apply,
            onStopped,
            hold: () => false,
            ...options,
        });

        return fetcher;
    }

    beforeEach(() => {
        load = vi.fn(async () => response(200));
        apply = vi.fn();
        onStopped = vi.fn();
    });

    afterEach(() => fetcher?.stop());

    it('reads once after a quiet time', async () => {
        make().request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS - 1);
        expect(load).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(load).toHaveBeenCalledTimes(1);
        expect(apply).toHaveBeenCalledTimes(1);
    });

    it('costs one read for a burst', async () => {
        make();
        fetcher.request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS / 2);
        fetcher.request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        expect(load).toHaveBeenCalledTimes(1);
    });

    it('reads after the longest wait when signals never stop', async () => {
        make();
        for (let elapsed = 0; elapsed < MAX_WAIT_MILLISECONDS; elapsed += 200) {
            fetcher.request();
            await vi.advanceTimersByTimeAsync(200);
        }
        expect(load).toHaveBeenCalledTimes(1);
    });

    it('holds the read until the hold ends', async () => {
        let held = true;
        make({ hold: () => held });
        fetcher.request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS + 1000);
        expect(load).not.toHaveBeenCalled();
        held = false;
        await vi.advanceTimersByTimeAsync(HOLD_RECHECK_MILLISECONDS);
        expect(load).toHaveBeenCalledTimes(1);
    });

    it('retries a network error and a 5xx after 1, 3 and 9 seconds, then stops', async () => {
        load.mockRejectedValueOnce(new Error('offline'));
        load.mockResolvedValue(response(503));
        make().request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        expect(load).toHaveBeenCalledTimes(1);
        for (const [index, wait] of RETRY_MILLISECONDS.entries()) {
            await vi.advanceTimersByTimeAsync(wait - 1);
            expect(load).toHaveBeenCalledTimes(index + 1);
            await vi.advanceTimersByTimeAsync(1);
            expect(load).toHaveBeenCalledTimes(index + 2);
        }
        expect(onStopped).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(60000);
        expect(load).toHaveBeenCalledTimes(4);
    });

    it.each([408, 429])('retries a %i', async (status) => {
        load.mockResolvedValueOnce(response(status));
        make().request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS[0]);
        expect(load).toHaveBeenCalledTimes(2);
        expect(apply).toHaveBeenCalledTimes(1);
        expect(onStopped).not.toHaveBeenCalled();
    });

    it('stops at once on a status that a retry cannot fix', async () => {
        load.mockResolvedValue(response(403));
        make().request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        expect(load).toHaveBeenCalledTimes(1);
        expect(onStopped).toHaveBeenCalledTimes(1);
    });

    it('shows the lost-access band and stops on a 401 or a 410', async () => {
        load.mockResolvedValue(lostResponse(410, 'project-deleted'));
        make().request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        expect(document.querySelectorAll('[data-live-lost-band]')).toHaveLength(
            1,
        );
        expect(apply).not.toHaveBeenCalled();
        expect(onStopped).not.toHaveBeenCalled();
        fetcher.request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS * 2);
        expect(load).toHaveBeenCalledTimes(1);
    });

    it('drops the answer of a read that a newer request made stale', async () => {
        let release;
        load.mockImplementationOnce(
            (signal) =>
                new Promise((resolve, reject) => {
                    release = () => resolve(response(200));
                    signal.addEventListener('abort', () =>
                        reject(new DOMException('aborted', 'AbortError')),
                    );
                }),
        );
        make().request();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        fetcher.request();
        release();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
        expect(load).toHaveBeenCalledTimes(2);
        expect(apply).toHaveBeenCalledTimes(1);
        expect(onStopped).not.toHaveBeenCalled();
    });

    it('does nothing after stop', async () => {
        make().request();
        fetcher.stop();
        await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS * 2);
        expect(load).not.toHaveBeenCalled();
    });
});
