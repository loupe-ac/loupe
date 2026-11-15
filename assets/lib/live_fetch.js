/**
 * The wait, the hold, the retry and the lost-access answer of a live fetch.
 * A live region and a controller with special needs both build on it.
 */

export const QUIET_MILLISECONDS = 300;
export const MAX_WAIT_MILLISECONDS = 2000;
export const HOLD_RECHECK_MILLISECONDS = 250;
export const RETRY_MILLISECONDS = [1000, 3000, 9000];
export const READ_TIMEOUT_MILLISECONDS = 10000;

export const REFRESH_HEADER = 'X-Loupe-Live-Refresh';
export const LOST_HEADER = 'X-Loupe-Live-Lost';

const LOST_BAND_ATTRIBUTE = 'data-live-lost-band';
const UNTYPED_INPUT_TYPES = new Set([
    'hidden',
    'submit',
    'button',
    'reset',
    'image',
    'checkbox',
    'radio',
    'file',
]);

const lostListeners = new Set();
let lost = false;

/** Tells whether a live fetch gets a 401 or a 410 that names the lost access. */
export function isLost() {
    return lost;
}

/**
 * Calls the listener once when a live fetch finds that the page lost access.
 *
 * @param {(cause: string) => void} listener
 * @returns {() => void} removes the listener
 */
export function onLostAccess(listener) {
    lostListeners.add(listener);

    return () => lostListeners.delete(listener);
}

/**
 * Handles the answer of a live fetch that lost access: a 401 or a 410 with the
 * X-Loupe-Live-Lost header. The page hides each [data-live-content] element,
 * tells the live parts to stop, and shows one warning band with the cause.
 *
 * @param {Response} response
 * @returns {boolean} true when the answer was a lost-access answer
 */
export function handleLostAccess(response) {
    if (response?.status !== 401 && response?.status !== 410) {
        return false;
    }
    const cause = response.headers?.get?.(LOST_HEADER);
    if (!cause) {
        return false;
    }
    if (lost) {
        return true;
    }
    lost = true;
    document
        .querySelectorAll('[data-live-content]')
        .forEach((element) => (element.hidden = true));
    showBand(cause, 'generic');
    [...lostListeners].forEach((listener) => listener(cause));

    return true;
}

/** Builds a fresh element from the band script of a name, or null. */
function bandOf(name) {
    const source = document.querySelector(
        `script[data-live-band-template="${name}"]`,
    );
    if (!source) {
        return null;
    }
    const holder = document.createElement('template');
    holder.innerHTML = source.textContent;

    return holder.content.firstElementChild;
}

/**
 * Puts the band of a template in the page, before the first hidden content
 * element, or at the top of the main area. A band that is already there stays.
 */
function showBand(name, fallbackName) {
    if (document.querySelector(`[${LOST_BAND_ATTRIBUTE}]`)) {
        return;
    }
    const band = bandOf(name) ?? bandOf(fallbackName);
    if (!band) {
        return;
    }
    band.setAttribute(LOST_BAND_ATTRIBUTE, name);
    const anchor = document.querySelector('[data-live-content]');
    if (anchor) {
        anchor.before(band);

        return;
    }
    (document.getElementById('main-content') ?? document.body).prepend(band);
}

/**
 * Shows the stopped band at the top of a live region, once.
 *
 * @param {Element} region
 */
export function showStoppedBand(region) {
    if (region.querySelector('[data-live-stopped-band]')) {
        return;
    }
    const band = bandOf('stopped');
    if (band) {
        band.setAttribute('data-live-stopped-band', '');
        region.prepend(band);
    }
}

/** Tells whether a field of the root holds text that the person typed. */
export function hasTypedText(root) {
    return [...root.querySelectorAll('input, textarea')].some((field) => {
        if (field instanceof HTMLInputElement) {
            if (UNTYPED_INPUT_TYPES.has(field.type)) {
                return false;
            }
        }

        return field.value !== field.defaultValue;
    });
}

/** The page holds a live update while a drag runs, a dialog is open or a field holds typed text. */
export function isHeld(root) {
    return (
        document.querySelector('[data-dragging], dialog[open]') !== null ||
        hasTypedText(root)
    );
}

/**
 * A read-only request that a live update sends, a GET unless it carries a body. The refresh header tells the
 * server to answer a lost access with a status, and to keep the flashes.
 *
 * @param {string} url
 * @param {{headers?: object, signal?: AbortSignal, body?: BodyInit}} options
 * @returns {Promise<Response>}
 */
export function liveRequest(url, { headers = {}, signal, body } = {}) {
    return fetch(url, {
        ...(body === undefined ? {} : { method: 'POST', body }),
        headers: { Accept: 'text/html', [REFRESH_HEADER]: '1', ...headers },
        credentials: 'same-origin',
        signal,
    });
}

function transient(status) {
    return status >= 500 || status === 408 || status === 429;
}

/**
 * Runs one live read at a time. A burst of requests waits for a quiet time and
 * costs one read, a steady stream reads after the longest wait, and a drag, an
 * open dialog or typed text holds the read. A network error, a 5xx, a 408 and
 * a 429 retry after 1, 3 and 9 seconds. Then the fetcher stops and calls
 * `onStopped`.
 */
export class LiveFetcher {
    /**
     * @param {object} options
     * @param {Element} options.root the element that holds the live part
     * @param {(signal: AbortSignal) => Promise<Response>} options.load
     * @param {(response: Response) => Promise|void} options.apply
     * @param {() => void} [options.onStopped]
     * @param {(root: Element) => boolean} [options.hold]
     */
    constructor({ root, load, apply, onStopped, hold = isHeld }) {
        this.root = root;
        this.load = load;
        this.apply = apply;
        this.onStopped = onStopped;
        this.hold = hold;
        this.stopped = false;
        this.attempt = 0;
    }

    /** Asks for a read. A newer request makes a read in flight stale. */
    request() {
        if (this.stopped || lost) {
            return;
        }
        this.attempt = 0;
        this.abortInFlight();
        clearTimeout(this.timer);
        this.firstRequestAt ??= Date.now();
        const sinceFirst = Date.now() - this.firstRequestAt;
        const wait = Math.min(
            QUIET_MILLISECONDS,
            Math.max(0, MAX_WAIT_MILLISECONDS - sinceFirst),
        );
        this.timer = setTimeout(() => this.run(), wait);
    }

    /** Stops every timer and read. The fetcher answers no more requests. */
    stop() {
        this.stopped = true;
        clearTimeout(this.timer);
        this.abortInFlight();
    }

    abortInFlight() {
        this.inFlight?.abort();
        this.inFlight = undefined;
    }

    async run() {
        if (this.stopped || lost) {
            return;
        }
        if (this.hold(this.root)) {
            this.timer = setTimeout(
                () => this.run(),
                HOLD_RECHECK_MILLISECONDS,
            );

            return;
        }
        this.firstRequestAt = undefined;
        const abort = new AbortController();
        this.inFlight = abort;
        let timedOut = false;
        const timeout = setTimeout(() => {
            timedOut = true;
            abort.abort();
        }, READ_TIMEOUT_MILLISECONDS);
        let response;
        try {
            response = await this.load(abort.signal);
        } catch {
            clearTimeout(timeout);
            if (abort.signal.aborted && !timedOut) {
                return;
            }
            this.fail(true);

            return;
        }
        if (abort.signal.aborted || this.stopped) {
            clearTimeout(timeout);

            return;
        }
        if (handleLostAccess(response)) {
            clearTimeout(timeout);
            this.stop();

            return;
        }
        if (!response.ok) {
            clearTimeout(timeout);
            this.fail(transient(response.status));

            return;
        }
        if (this.hold(this.root)) {
            clearTimeout(timeout);
            this.timer = setTimeout(
                () => this.run(),
                HOLD_RECHECK_MILLISECONDS,
            );

            return;
        }
        try {
            await Promise.race([
                this.apply(response),
                new Promise((_, reject) =>
                    abort.signal.addEventListener('abort', () =>
                        reject(new Error('aborted')),
                    ),
                ),
            ]);
        } catch {
            if (!abort.signal.aborted || timedOut) {
                this.fail(true);
            }

            return;
        } finally {
            clearTimeout(timeout);
        }
        this.inFlight = undefined;
        this.attempt = 0;
    }

    fail(canRetry) {
        this.inFlight = undefined;
        if (canRetry && this.attempt < RETRY_MILLISECONDS.length) {
            const wait = RETRY_MILLISECONDS[this.attempt];
            this.attempt += 1;
            this.timer = setTimeout(() => this.run(), wait);

            return;
        }
        this.stop();
        this.onStopped?.();
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('turbo:load', () => {
        lost = false;
    });
}

/** Forgets the lost state and every listener, for tests. */
export function reset() {
    lost = false;
    lostListeners.clear();
}
