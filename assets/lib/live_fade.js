export const FADE_MILLISECONDS = 200;

const LEAVING = 'lp-live-row--leaving';
const ENTERING = 'lp-live-row--entering';

function reducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

function fadeOut(element) {
    if (reducedMotion()) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        element.classList.add(LEAVING);
        setTimeout(resolve, FADE_MILLISECONDS);
    });
}

function streamTargets(stream) {
    const owner = stream.ownerDocument ?? document;
    if (stream.target) {
        const element = owner.getElementById(stream.target);

        return element ? [element] : [];
    }
    const query = stream.targets;

    return query ? [...owner.querySelectorAll(query)] : [];
}

/** A remove stream fades out the target that carries data-live-fade, and then removes it. */
function onBeforeStreamRender(event) {
    const stream = event.detail?.newStream;
    if (stream?.action !== 'remove') {
        return;
    }
    const fading = streamTargets(stream).filter(
        (element) => element.dataset.liveFade !== undefined,
    );
    if (fading.length === 0) {
        return;
    }
    const render = event.detail.render;
    event.detail.render = async (renderedStream) => {
        await Promise.all(fading.map(fadeOut));
        render(renderedStream);
    };
}

function rowsOf(element) {
    return [...element.querySelectorAll('[data-live-row]')];
}

/**
 * A frame morph keeps the rows that left in place while they fade out, and
 * marks the rows that entered. A leaving row is a copy in the new element, at
 * the place it had, with no row id, so that a later morph does not copy it again.
 * The morph can keep the row that stands in the page for the copy, so the end of
 * the fade removes every leaving row of the page, not only the copy.
 */
function onBeforeFrameMorph(event) {
    const current = event.target;
    const fresh = event.detail?.newElement;
    if (!current || !fresh || reducedMotion()) {
        return;
    }
    const freshRows = new Map(
        rowsOf(fresh).map((row) => [row.dataset.liveRow, row]),
    );
    const currentIds = new Set(
        rowsOf(current).map((row) => row.dataset.liveRow),
    );

    freshRows.forEach((row, id) => {
        if (!currentIds.has(id)) {
            row.classList.add(ENTERING);
        }
    });

    rowsOf(current).forEach((row) => {
        if (freshRows.has(row.dataset.liveRow)) {
            return;
        }
        const copy = row.cloneNode(true);
        delete copy.dataset.liveRow;
        copy.classList.add(LEAVING);
        placeCopy(row, copy, freshRows, current, fresh);
        setTimeout(() => {
            copy.remove();
            current
                .querySelectorAll(`.${LEAVING}`)
                .forEach((leaving) => leaving.remove());
        }, FADE_MILLISECONDS);
    });
}

/**
 * Puts the copy after the nearest earlier row that stays, or before the nearest
 * later one. With no row left, it goes into the twin of the parent of the row,
 * so that a list that empties still fades its last rows.
 */
function placeCopy(row, copy, freshRows, current, fresh) {
    for (let sibling = row.previousElementSibling; sibling; ) {
        const twin = freshRows.get(sibling.dataset?.liveRow);
        if (twin) {
            twin.after(copy);

            return true;
        }
        sibling = sibling.previousElementSibling;
    }
    for (let sibling = row.nextElementSibling; sibling; ) {
        const twin = freshRows.get(sibling.dataset?.liveRow);
        if (twin) {
            twin.before(copy);

            return true;
        }
        sibling = sibling.nextElementSibling;
    }
    const parent = row.parentElement;
    const twinParent =
        parent && parent !== current && parent.id
            ? fresh.querySelector(`#${CSS.escape(parent.id)}`)
            : null;
    (twinParent ?? fresh).append(copy);

    return true;
}

let registered = false;

/** Registers the two document hooks, once. */
export function registerFadeHooks() {
    if (registered) {
        return;
    }
    registered = true;
    document.addEventListener(
        'turbo:before-stream-render',
        onBeforeStreamRender,
    );
    document.addEventListener('turbo:before-frame-morph', onBeforeFrameMorph);
}

if (typeof document !== 'undefined') {
    registerFadeHooks();
}

/** Removes the hooks, for tests. */
export function unregisterFadeHooks() {
    registered = false;
    document.removeEventListener(
        'turbo:before-stream-render',
        onBeforeStreamRender,
    );
    document.removeEventListener(
        'turbo:before-frame-morph',
        onBeforeFrameMorph,
    );
}
