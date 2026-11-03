(() => {
    // Idempotency guard. The widget's host elements are attached to <html>, so they
    // survive a Turbo (or any SPA) <body> swap; without this, every navigation that
    // re-executes the script tag would append another launcher/overlay and the
    // shadows would stack up. The flag lives on window, which persists across such
    // navigations, so only the first execution initializes.
    if (window.__loupeSiteReviewLoaded) return;
    window.__loupeSiteReviewLoaded = true;

    const script = document.currentScript;
    const BACKEND = new URL(script.src).origin;
    // A worktree preview serves this script from a local host, so the launcher
    // can say where the comments land with no attribute and no API field. A
    // public deployment cannot switch it on by accident.
    const BACKEND_HOST = new URL(BACKEND).hostname;
    const LOCAL =
        /(^|\.)localhost$/.test(BACKEND_HOST) ||
        /^127\./.test(BACKEND_HOST) ||
        /\.local$/.test(BACKEND_HOST);
    // The embed names a project. The reviewer signs in with OAuth, so no
    // credential sits in the page source.
    const PROJECT = script.getAttribute('data-project') || '';
    const OAUTH = !!PROJECT;
    // A preview page names the card it is for as `card:<uuid>`. Notes on that
    // page go to that card, whatever mode the reviewer chose elsewhere.
    const CONTEXT = script.getAttribute('data-context') || '';
    // A preview page can carry a token that signs the widget in with no popup.
    // A popup grant wins over it, and a 401 ends that one token only.
    const PREVIEW_TOKEN = script.getAttribute('data-preview-token') || '';
    const PREVIEW_ENDED =
        'The preview sign-in for this page ended. Sign in, or restart the preview.';
    let endedPreviewToken = '';
    // Read again rather than kept, because an SPA swap replaces the tag and may
    // carry a different marker. `script` is the one this ran from and goes
    // stale; the document is the source of truth afterwards.
    const pageMarker = () => {
        const tag = document.querySelector(
            'script[src*="live-review/widget.js"]',
        );

        // A tag with no attribute means this page names no card, and must not
        // fall back to the marker the widget booted with: navigating from a
        // marked page to an unmarked one would otherwise keep the first card.
        // Only the absence of a tag falls back, which is the swap-in-progress
        // case rather than a statement about the page.
        return tag ? tag.getAttribute('data-context') || '' : CONTEXT;
    };
    let lastSeenMarker = CONTEXT;
    // Read again for the reason the marker is: a Turbo visit swaps the tag, and
    // a restarted preview carries a new token.
    const pagePreviewToken = () => {
        const tag = document.querySelector(
            'script[src*="live-review/widget.js"]',
        );

        return tag
            ? tag.getAttribute('data-preview-token') || ''
            : PREVIEW_TOKEN;
    };
    const livePreviewToken = () => {
        const token = pagePreviewToken();

        return token === endedPreviewToken ? '' : token;
    };
    const previewEndedMessage = () =>
        endedPreviewToken && pagePreviewToken() === endedPreviewToken
            ? PREVIEW_ENDED
            : '';
    let lastSeenPreviewToken = PREVIEW_TOKEN;
    let refreshGeneration = 0;
    // The newest list request, and whether it has no answer yet.
    let listReady = Promise.resolve();
    let refreshPending = false;
    // Whether `comments` can miss notes of the scope, after a save pushed a
    // card out. Only a scoped read that succeeds clears it, and Delete all
    // waits for one.
    let scopeUnread = false;
    // Every comment is saved to the API as it is written and is live from that
    // moment — there is no send step. `comments` mirrors the project's Pending
    // comments, the ones this reviewer may still edit or delete; once the agent marks
    // one addressed it drops out. Each item: { id, body, url, anchors }, and an anchor is
    // { selector, text, quote, quotePrefix, quoteSuffix } — several when the comment says
    // how elements relate, none for a page note, and quoting when it points at a run of
    // text inside its element rather than at the whole element.
    let comments = [];
    // Counts the scope switches. A save that began under an older scope
    // keeps its note out of the new list.
    let scopeGeneration = 0;
    // Whether this instance offers freehand drawing. The boot load carries it,
    // because the embed snippet lives in someone else's page and nobody re-pastes
    // it. Off until the server says otherwise: a widget that offered a control
    // the API then refuses would lose the reviewer's gesture. Strokes already
    // saved render whatever this says, so switching the flag off hides no data.
    let drawingEnabled = false;
    // The card types that can have children, from the boot load. Empty hides
    // the mode "a card for each note, under an epic".
    let parentTypes = [];
    // Where notes go, kept per instance and project until the reviewer changes
    // it: `per-note` makes a card for each note, `per-review` adds every note
    // to one card, and `epic` makes a card for each note under one epic.
    const MODES = ['per-note', 'per-review', 'epic'];
    const MODE_KEY = `loupe-site-review:mode:${BACKEND}:${PROJECT}`;
    const readStoredTarget = () => {
        try {
            const stored = JSON.parse(
                window.localStorage.getItem(MODE_KEY) || 'null',
            );
            if (!stored || !MODES.includes(stored.mode)) return null;
            if (stored.mode === 'per-note') {
                return { mode: 'per-note', cardId: null };
            }
            return typeof stored.cardId === 'string' && stored.cardId
                ? { mode: stored.mode, cardId: stored.cardId }
                : null;
        } catch {
            return null;
        }
    };
    const writeStoredTarget = (target) => {
        try {
            if (target) {
                window.localStorage.setItem(
                    MODE_KEY,
                    JSON.stringify({
                        mode: target.mode,
                        cardId: target.cardId,
                    }),
                );
            } else {
                window.localStorage.removeItem(MODE_KEY);
            }
        } catch {
            /* storage unavailable — the choice still holds for this page */
        }
    };
    // { mode, cardId, label, url }. The label arrives when the boot load
    // confirms the card, or from the picker that chose it.
    let storedTarget = readStoredTarget();
    // The cards this tab made for its notes, newest first. A per-note review
    // lists them and nothing else, and the list lives in session storage so a
    // reload keeps it while a new tab starts empty.
    const SESSION_CARDS_KEY = `loupe-site-review:session-cards:${BACKEND}:${PROJECT}`;
    const SESSION_CARDS_SENT = 50; // the server reads this many ids at most
    const SESSION_CARDS_KEPT = 200;
    const readSessionCards = () => {
        try {
            const stored = JSON.parse(
                window.sessionStorage.getItem(SESSION_CARDS_KEY) || '[]',
            );
            return Array.isArray(stored)
                ? stored.filter((id) => typeof id === 'string' && id)
                : [];
        } catch {
            return [];
        }
    };
    let sessionCards = readSessionCards();
    const writeSessionCards = (ids) => {
        sessionCards = ids;
        try {
            if (ids.length) {
                window.sessionStorage.setItem(
                    SESSION_CARDS_KEY,
                    JSON.stringify(ids),
                );
            } else {
                window.sessionStorage.removeItem(SESSION_CARDS_KEY);
            }
        } catch {
            /* storage unavailable — the list still holds for this page */
        }
    };
    const rememberSessionCard = (cardId) => {
        if (typeof cardId !== 'string' || !cardId) return;
        writeSessionCards(
            [cardId, ...sessionCards.filter((id) => id !== cardId)].slice(
                0,
                SESSION_CARDS_KEPT,
            ),
        );
    };
    // The card a preview page names. It wins over the stored mode and never
    // changes it. A broken lock refuses notes rather than filing them elsewhere.
    const lockFor = (marker) => {
        const match = /^card:(.+)$/.exec(marker);
        return match
            ? { cardId: match[1], label: null, url: null, state: 'checking' }
            : null;
    };
    let lock = lockFor(CONTEXT);
    // The marker the boot load checks. A per-note mode names no card.
    const markerOf = (target) => {
        if (!target || target.mode === 'per-note') return '';
        return `${target.mode === 'epic' ? 'epic' : 'card'}:${target.cardId}`;
    };
    const bootMarker = () =>
        lock ? `card:${lock.cardId}` : markerOf(storedTarget);
    // The notes the list shows. An open review with no destination yet asks
    // for no cards, so it lists nothing. A broken lock still asks, because the
    // answer is how the widget learns the card is gone.
    const scopeOf = () => {
        if (lock) return `card:${lock.cardId}`;
        const target = storedTarget;
        if (target && target.mode === 'per-review') {
            return `card:${target.cardId}`;
        }
        if (target && target.mode === 'epic') return `epic:${target.cardId}`;

        return `cards:${sessionCards.slice(0, SESSION_CARDS_SENT).join(',')}`;
    };
    // What a save sends notes to, or null while the reviewer must choose.
    const activeTarget = () => {
        if (!lock) return storedTarget;
        if (lock.state === 'broken') return null;
        return {
            mode: 'per-review',
            cardId: lock.cardId,
            label: lock.label,
            url: lock.url,
        };
    };
    // The second header line of an open review: what the destination does.
    const targetLine = (target) =>
        target.mode === 'per-note'
            ? 'Each note makes its own card'
            : target.mode === 'epic'
              ? 'Each note makes a card under the epic'
              : 'One card for this review';
    const targetLabel = (target) => {
        if (target.mode === 'per-note') return 'A new card for each note';
        if (target.label) {
            return target.mode === 'epic'
                ? `Cards under ${target.label}`
                : target.label;
        }
        return target.mode === 'epic'
            ? 'Cards under an epic'
            : 'One card for this review';
    };
    // The mode whose card the picker is searching for, or null while it lists
    // the modes.
    let pickerMode = null;
    let pickerCards = [];
    let pickerError = null;
    let pickerBusy = false;
    // Separate from pickerBusy on purpose. Typing a title and clicking Create
    // is the fastest path through this panel, and the search it triggered is
    // usually still in flight: a shared flag made Create do nothing at all.
    let pickerCreating = false;
    const MAX_ANCHORS = 10; // AddCommentRequest's cap — over it the API 422s
    const AT_CAP_MESSAGE = `A note can point at ${MAX_ANCHORS} elements at most.`;
    // AddCommentRequest's caps for the drawing. A stroke past the point cap stops
    // growing rather than 422ing the save the reviewer already committed to.
    const MAX_STROKES = 50;
    const MAX_STROKE_POINTS = 500;
    const AT_STROKE_CAP_MESSAGE = `A note can carry ${MAX_STROKES} strokes at most.`;
    const MIN_POINT_GAP = 2; // px of pointer travel before another point is kept
    // SiteReviewAnchorInput caps quote, quotePrefix and quoteSuffix at 2000 each. The
    // widget refuses well under that, because a quote long enough to approach it is a
    // whole section rather than the passage the reviewer means.
    const QUOTE_MAX = 1000;
    const LONG_QUOTE_MESSAGE = `A quote can be ${QUOTE_MAX} characters at most. Select a shorter passage.`;

    // Hold this key to add another element to the comment being composed. Ctrl is
    // the modifier away from a Mac, where Ctrl+click is a right click.
    // Either source is enough, because Ctrl+click on a Mac is a right click and a
    // widget that reads one spoofed value wrong there picks nothing at all.
    // userAgentData says 'macOS', navigator.platform says 'MacIntel'.
    const IS_MAC = /mac|iphone|ipad|ipod/i.test(
        `${navigator.platform || ''} ${(navigator.userAgentData && navigator.userAgentData.platform) || ''}`,
    );
    const MOD_KEY = IS_MAC ? 'Meta' : 'Control';
    const MOD_LABEL = IS_MAC ? '⌘' : 'Ctrl';
    const ALT_LABEL = IS_MAC ? '⌥' : 'Alt';
    const modHeld = (event) => (IS_MAC ? event.metaKey : event.ctrlKey);

    const anchorsOf = (comment) =>
        Array.isArray(comment.anchors) ? comment.anchors : [];

    // Strokes of a saved comment, tolerating a server that predates the column.
    const strokesOf = (comment) =>
        Array.isArray(comment.strokes) ? comment.strokes : [];

    // ---- OAuth sign-in ----
    const OAUTH_CLIENT_ID = 'loupe-site-review-widget';
    const OAUTH_REDIRECT_URI = `${BACKEND}/oauth/widget/callback`;
    const OAUTH_MESSAGE_TYPE = 'loupe-site-review-oauth';
    // Per backend and project, so two embeds on one site keep separate grants.
    const OAUTH_STORAGE_KEY = `loupe-site-review:oauth:${BACKEND}:${PROJECT}`;
    const OAUTH_EXPIRY_MARGIN_MS = 60 * 1000;

    // sessionStorage can be absent or throw (a sandboxed frame, blocked site
    // data). The grant then lives for this page load only.
    const readGrant = () => {
        try {
            const grant = JSON.parse(
                window.sessionStorage.getItem(OAUTH_STORAGE_KEY) || 'null',
            );
            return grant &&
                typeof grant.accessToken === 'string' &&
                typeof grant.refreshToken === 'string' &&
                typeof grant.expiresAt === 'number'
                ? grant
                : null;
        } catch {
            return null;
        }
    };
    let grant = OAUTH ? readGrant() : null;
    const setGrant = (next) => {
        grant = next;
        try {
            if (next) {
                window.sessionStorage.setItem(
                    OAUTH_STORAGE_KEY,
                    JSON.stringify(next),
                );
            } else {
                window.sessionStorage.removeItem(OAUTH_STORAGE_KEY);
            }
        } catch {
            /* the in-memory grant still works for this page */
        }
    };

    const base64Url = (bytes) =>
        btoa(String.fromCharCode(...bytes))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');
    const randomToken = (size) =>
        base64Url(crypto.getRandomValues(new Uint8Array(size)));
    const pkceChallenge = async (verifier) =>
        base64Url(
            new Uint8Array(
                await crypto.subtle.digest(
                    'SHA-256',
                    new TextEncoder().encode(verifier),
                ),
            ),
        );

    const signedOutError = (message) =>
        Object.assign(new Error('signed out'), {
            status: 401,
            code: 'signed_out',
            message: message || '',
        });

    const tokenRequest = async (parameters) => {
        const response = await fetch(`${BACKEND}/oauth/token`, {
            method: 'POST',
            credentials: 'omit',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                client_id: OAUTH_CLIENT_ID,
                ...parameters,
            }).toString(),
        });
        let payload = null;
        try {
            payload = await response.json();
        } catch {
            /* an unreadable body is a failure like any other */
        }
        if (
            !response.ok ||
            !payload ||
            typeof payload.access_token !== 'string' ||
            typeof payload.refresh_token !== 'string'
        ) {
            throw Object.assign(new Error(`HTTP ${response.status}`), {
                status: response.status,
            });
        }
        return {
            accessToken: payload.access_token,
            refreshToken: payload.refresh_token,
            expiresAt: Date.now() + (Number(payload.expires_in) || 0) * 1000,
        };
    };

    // One refresh at a time. The server rotates the refresh token, so a second
    // request that used the same one would fail and sign the reviewer out.
    let refreshing = null;
    const refreshGrant = () => {
        if (!refreshing) {
            refreshing = tokenRequest({
                grant_type: 'refresh_token',
                refresh_token: grant.refreshToken,
            })
                .then((next) => setGrant(next))
                .catch((error) => {
                    // A refused refresh ends the grant. A network failure or a
                    // 5xx keeps it, so the next request can try again.
                    if (error.status >= 400 && error.status < 500)
                        setGrant(null);
                    throw grant ? error : signedOutError();
                })
                .finally(() => {
                    refreshing = null;
                });
        }
        return refreshing;
    };

    // A refused refresh ends the popup grant, and the page token takes over.
    const refreshOrFallBack = async () => {
        try {
            await refreshGrant();
        } catch (error) {
            if (grant || !livePreviewToken()) throw error;
        }
    };

    const accessToken = async () => {
        if (!OAUTH) return '';
        if (grant && grant.expiresAt - OAUTH_EXPIRY_MARGIN_MS <= Date.now())
            await refreshOrFallBack();
        if (!grant) {
            const preview = livePreviewToken();
            if (preview) return preview;
            throw signedOutError(previewEndedMessage());
        }
        return grant.accessToken;
    };

    const api = async (method, path, body, retried = false) => {
        const token = await accessToken();
        const preview = OAUTH && !grant ? token : '';
        const response = await fetch(`${BACKEND}${path}`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        // An access token can die before its expiry, for example when the owner
        // revokes the app. One refresh tells a dead token from a dead grant.
        if (OAUTH && response.status === 401 && !retried && grant) {
            await refreshOrFallBack();
            return api(method, path, body, true);
        }
        if (OAUTH && response.status === 401) {
            setGrant(null);
            if (preview) endedPreviewToken = preview;
            throw signedOutError(previewEndedMessage());
        }
        if (!response.ok) {
            // Carry the status so callers can tell a permanent auth/config failure (401/403 —
            // invalid, revoked, or unlinked token) from a transient one (network, 5xx, 429).
            const error = new Error(`HTTP ${response.status}`);
            error.status = response.status;
            // The API's auth/authorization failures carry a machine-readable { error: code }
            // body (code, or error on a flat answer: invalid_credentials / credential_not_allowed /
            // token_not_bound_to_site, or their old names); read it to
            // tailor the fatal message. Guarded — a non-JSON body must never throw here.
            try {
                const data = await response.json();
                if (data && typeof data.code === 'string')
                    error.code = data.code;
                else if (data && typeof data.error === 'string')
                    error.code = data.error;
            } catch {
                /* no JSON body — the status alone still classifies the failure */
            }
            throw error;
        }
        return response.status === 204 ? null : response.json();
    };

    // A 401/403 from ANY endpoint means the widget's token is unusable — loading, saving
    // and deleting all fail the same way — so it is not a per-action hiccup. Callers
    // promote it to the fatal state instead of showing a dismissible inline error.
    const authFailed = (error) =>
        !!error && (error.status === 401 || error.status === 403);
    const fatalFrom = (error) => ({
        status: error.status,
        code: error.code,
        message: 'signed_out' === error.code ? error.message : undefined,
    });
    // Enter the terminal fatal state: record the cause and tear down everything interactive
    // so the critical panel actually surfaces cleanly. Drops the in-memory list (so no
    // stale pins/rows/highlights linger as clickable dead ends), and exits pick and compose
    // modes — pick mode in particular hides the whole widget behind its scrim and keeps
    // document-level click listeners, which a boot rejection landing after the user entered
    // pick mode would otherwise leave stuck on the page.
    const enterFatal = (error) => {
        state.fatal = fatalFrom(error);
        comments = [];
        setTargeting(false);
        setDrawing(false);
        state.strokes = [];
        state.composeTarget = null;
        state.editId = null;
        // A signed-out reviewer gets the sign-in view, and any other refusal
        // replaces the views with the error panel.
        resetView('signed_out' === state.fatal.code ? 'signin' : 'list');
        pickerMode = null;
        state.draft = '';
        state.actionError = null;
        state.savedNotice = null;
        state.quotePick = null;
        textareaNode.value = '';
    };

    // Rehydrate the list from the project's Pending comments.
    const refresh = (options) => (listReady = loadList(options));
    const loadList = async ({ firstLoad = false } = {}) => {
        // The marker this answer will describe, read once. Navigation or a new
        // choice can change it while the request is in flight, and an answer
        // about one card must not label another.
        const asked = bootMarker();
        const scope = scopeOf();
        const generation = ++refreshGeneration;
        refreshPending = true;
        try {
            const payload = await api(
                'GET',
                '/api/v1/live-review-widget/review?scope=' +
                    encodeURIComponent(scope) +
                    (asked ? '&context=' + encodeURIComponent(asked) : ''),
            );
            // Before anything is written. A superseded answer is a snapshot
            // from before the newer one, so letting it through would resurrect
            // a deleted comment or drop a new one, not only mislabel the card.
            if (generation !== refreshGeneration) return;
            comments = payload.comments || [];
            scopeUnread = false;
            drawingEnabled = true === payload.drawingEnabled;
            parentTypes = Array.isArray(payload.parentTypes)
                ? payload.parentTypes
                : [];
            const named = payload.context || null;
            if (asked && asked === bootMarker()) {
                if (lock) {
                    lock.state = named ? 'ok' : 'broken';
                    lock.label = named ? named.label : null;
                    lock.url = named ? named.url : null;
                } else if (named) {
                    storedTarget = { ...storedTarget, ...named };
                } else {
                    // Deleted, closed, another project's, or not an epic: the
                    // save would refuse it, so the reviewer chooses again.
                    storedTarget = null;
                    writeStoredTarget(null);
                }
            }
            // Repaint, or the row keeps the previous page's card while the
            // save already carries the new one. Every other caller of this
            // renders afterwards; a navigation refresh has nobody to do it.
            sync();
            followLockForVerdict();
        } catch (error) {
            // A superseded failure says nothing about the state a newer answer
            // has already established. Its firstLoad branch would blank a list
            // that had just loaded, and even its auth branch is wrong here: a
            // newer request that succeeded is proof the token works.
            if (generation !== refreshGeneration) return;
            // Catch a rejected token at the earliest possible point — the boot load — so the
            // widget opens straight into its critical state instead of a misleading empty list.
            if (authFailed(error)) {
                enterFatal(error);
            } else if (firstLoad) {
                // A later failure keeps the list it already holds: the comments are safe on
                // the server, and blanking the screen would say otherwise. Only the boot
                // load has nothing to keep.
                comments = [];
            }
        } finally {
            if (generation === refreshGeneration) refreshPending = false;
        }
    };

    const SELECTOR_MAX = 2000; // AddCommentRequest's cap — over it the API 400s
    const TEXT_MAX = 200; // how much anchor text the widget keeps, well under the cap

    // Comment bodies and anchor labels are arbitrary host-page text rendered into
    // innerHTML on a third-party page — every dynamic value MUST go through this.
    const escapeHtml = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (character) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[character],
        );

    // Build a stable-ish CSS selector for an element (used for re-anchoring on revisit).
    // A class-heavy page can outgrow the server's SELECTOR_MAX cap, so precision is
    // shed until it fits — classes first, then outermost ancestors — because a
    // less specific anchor beats a comment the API rejects.
    const selectorFor = (element) => {
        if (element.id) {
            const byId = `#${CSS.escape(element.id)}`;
            if (byId.length <= SELECTOR_MAX) return byId;
        }
        const parts = [];
        let current = element;
        while (current && current.nodeType === 1 && parts.length < 5) {
            const classes = [...current.classList].map((className) =>
                CSS.escape(className),
            );
            let nth = '';
            const parent = current.parentElement;
            if (parent) {
                const siblings = [...parent.children].filter(
                    (child) => child.tagName === current.tagName,
                );
                if (siblings.length > 1)
                    nth = `:nth-of-type(${siblings.indexOf(current) + 1})`;
            }
            parts.unshift({ tag: current.tagName.toLowerCase(), classes, nth });
            current = current.parentElement;
        }

        const render = (from, withClasses) =>
            parts
                .slice(from)
                .map(
                    (part) =>
                        part.tag +
                        (withClasses && part.classes.length
                            ? '.' + part.classes.join('.')
                            : '') +
                        part.nth,
                )
                .join(' > ');

        for (const withClasses of [true, false]) {
            for (let from = 0; from < parts.length; from++) {
                const selector = render(from, withClasses);
                if (selector.length <= SELECTOR_MAX) return selector;
            }
        }
        return '';
    };

    const queryOne = (selector) => {
        try {
            return document.querySelector(selector);
        } catch {
            return null;
        }
    };

    // A compound selectorFor writes: a tag, then its classes, then an optional
    // :nth-of-type(). Dropping the classes leaves the two ends.
    const bareCompound = (compound) => {
        const tag = /^[a-z][a-z0-9-]*/i.exec(compound);
        if (!tag) return null;
        const nth = /:nth-of-type\(\d+\)$/.exec(compound);
        return tag[0] + (nth ? nth[0] : '');
    };
    // `>` separates two compounds, unless a backslash escapes it: CSS.escape
    // writes a Tailwind class such as [&>svg]:hidden with one, and splitting
    // there would cut a class in half.
    const splitCompounds = (selector) => {
        const parts = [''];
        for (let index = 0; index < selector.length; index += 1) {
            const char = selector[index];
            if (char === '\\') {
                parts[parts.length - 1] += char + (selector[index + 1] || '');
                index += 1;
            } else if (char === '>') parts.push('');
            else parts[parts.length - 1] += char;
        }
        return parts.map((part) => part.trim());
    };
    // A stored selector carries every class the element had when the comment was
    // saved, including ones the host page adds for hover or an expanded state.
    // Those are gone on a later visit, so an exact match misses an element that
    // is still there. These are the same selector with classes dropped from some
    // of its compounds, fewest drops first.
    const relaxedSelectors = (selector) => {
        const parts = splitCompounds(selector);
        const bare = parts.map(bareCompound);
        const loose = parts
            .map((part, index) =>
                bare[index] && bare[index] !== part ? index : -1,
            )
            .filter((index) => index >= 0);
        const candidates = [];
        for (let mask = 1; mask < 1 << loose.length; mask++) {
            const dropped = loose.filter((_, slot) => mask & (1 << slot));
            candidates.push({
                drops: dropped.length,
                selector: parts
                    .map((part, index) =>
                        dropped.includes(index) ? bare[index] : part,
                    )
                    .join(' > '),
            });
        }
        return candidates
            .sort((a, b) => a.drops - b.drops)
            .map((entry) => entry.selector);
    };
    const flatText = (value) =>
        String(value || '')
            .replace(/\s+/g, ' ')
            .trim();
    // The element a stored anchor names. A relaxed selector counts only when it
    // matches exactly one element and that element still reads like the one the
    // reviewer picked. A second match means the classes just dropped were what
    // told the two apart, and uniqueness alone says nothing about identity: the
    // original element may be gone, leaving its :nth-of-type() seat to another.
    // Drawing a comment on the wrong element is worse than drawing none.
    const queryAnchor = (selector, text) => {
        const exact = queryOne(selector);
        if (exact) return exact;
        const said = flatText(text);
        for (const candidate of relaxedSelectors(selector)) {
            let found;
            try {
                found = document.querySelectorAll(candidate);
            } catch {
                continue;
            }
            if (found.length !== 1) continue;
            const reads = flatText(found[0].innerText || found[0].textContent);
            if (!said || reads === said) return found[0];
            if (reads && (reads.includes(said) || said.includes(reads)))
                return found[0];
        }
        return null;
    };

    // ---- text quotes (the W3C TextQuoteSelector shape) ----
    // Characters of context kept on each side of a quote, and how many of them a
    // match is confirmed on. Both mirror the document reviewer's AnchorService,
    // which solved this same problem against a rendered document.
    const QUOTE_CONTEXT = 32;
    const QUOTE_FINGERPRINT = 8;

    // The last `count` codepoints of `text` ending at the UTF-16 offset `index`.
    // A plain slice would build a shorter window, and could halve a surrogate
    // pair, around an emoji.
    const beforeText = (text, index, count) =>
        Array.from(text.slice(Math.max(0, index - count * 2), index))
            .slice(-count)
            .join('');
    // The first `count` codepoints of `text` starting at the UTF-16 offset `index`.
    const afterText = (text, index, count) =>
        Array.from(text.slice(index, index + count * 2))
            .slice(0, count)
            .join('');

    const textWalker = (root) =>
        document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);

    // Offset at which `element`'s own text begins within `root`'s textContent.
    const elementStartOffset = (root, element) => {
        const walker = textWalker(root);
        let offset = 0;
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            // A descendant of the element reports FOLLOWING too, so this stops at the
            // element's own subtree as well as at anything after it.
            if (
                element.compareDocumentPosition(node) &
                Node.DOCUMENT_POSITION_FOLLOWING
            )
                break;
            offset += node.textContent.length;
        }
        return offset;
    };

    // Offset of (node, offsetInNode) within `root`'s textContent. A range boundary
    // can land on an element rather than a text node — a triple-click selects a
    // whole block — and the offset then counts child nodes, not characters.
    const textOffsetIn = (root, node, offsetInNode) => {
        if (node.nodeType === 1) {
            const before = [...node.childNodes]
                .slice(0, offsetInNode)
                .reduce((total, child) => total + child.textContent.length, 0);
            return elementStartOffset(root, node) + before;
        }
        const walker = textWalker(root);
        let offset = 0;
        for (
            let current = walker.nextNode();
            current;
            current = walker.nextNode()
        ) {
            if (current === node) return offset + offsetInNode;
            offset += current.textContent.length;
        }
        return offset;
    };

    // A [start, end) span of `root`'s textContent as a live DOM Range.
    const rangeForSpan = (root, start, end) => {
        const walker = textWalker(root);
        const range = document.createRange();
        let offset = 0;
        let startSet = false;
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const length = node.textContent.length;
            if (!startSet && start <= offset + length) {
                range.setStart(node, start - offset);
                startSet = true;
            }
            if (startSet && end <= offset + length) {
                range.setEnd(node, end - offset);
                return range;
            }
            offset += length;
        }
        return null;
    };

    // Where an anchor's quote reads now, as a Range, or null when it no longer
    // reads at all, and null again when nothing separates two occurrences.
    // textContent, never innerText: innerText collapses whitespace, so it would
    // count different characters from the ones the capture counted.
    const quoteRange = (el, anchor) => {
        const quote = (anchor && anchor.quote) || '';
        if (!el || !quote) return null;
        const haystack = el.textContent || '';
        const hits = [];
        for (
            let at = haystack.indexOf(quote);
            at !== -1;
            at = haystack.indexOf(quote, at + 1)
        ) {
            hits.push(at);
        }
        if (!hits.length) return null;
        const prefix = anchor.quotePrefix || '';
        const suffix = anchor.quoteSuffix || '';
        const score = (start) => {
            let value = 0;
            const head = beforeText(haystack, start, QUOTE_CONTEXT);
            const tail = afterText(
                haystack,
                start + quote.length,
                QUOTE_CONTEXT,
            );
            if (
                prefix &&
                head.endsWith(
                    beforeText(prefix, prefix.length, QUOTE_FINGERPRINT),
                )
            )
                value += 1;
            if (
                suffix &&
                tail.startsWith(afterText(suffix, 0, QUOTE_FINGERPRINT))
            )
                value += 1;
            return value;
        };
        const ranked = hits
            .map((at) => ({ at, rank: score(at) }))
            .sort((a, b) => b.rank - a.rank || a.at - b.at);
        // Resolve only when one occurrence outranks every other. A tie means the
        // stored context does not say which one the reviewer meant, and position
        // alone would be a coin flip, so the anchor degrades to its element. A lone
        // hit needs no context, because there is nothing else it could be.
        if (ranked.length > 1 && ranked[0].rank === ranked[1].rank) return null;
        const start = ranked[0].at;
        try {
            return rangeForSpan(el, start, start + quote.length);
        } catch {
            return null;
        }
    };

    // Every anchor paired with the element it resolves to on this page, or null
    // when it no longer matches. Empty off-page and for an unanchored comment.
    // `range` is resolved fresh every pass, because the host page's nodes are
    // replaced under it. A stale quote leaves it null and `el` intact, which is
    // how the anchor degrades to its element instead of dropping the comment.
    const resolveAnchors = (comment) => {
        if (comment.url !== location.href) return [];
        return anchorsOf(comment).map((anchor, anchorIndex) => {
            const el = anchor.selector
                ? queryAnchor(anchor.selector, anchor.text)
                : null;
            return { anchor, anchorIndex, el, range: quoteRange(el, anchor) };
        });
    };

    // A comment is degraded when it was anchored to several elements and at least
    // one of them is gone. Rendering the survivors as if the comment had always
    // been about them would misstate what the reviewer said, so the widget says so.
    // A single-anchor comment that no longer resolves is still dropped in silence.
    const anchorHealth = (comment) => {
        const resolved = resolveAnchors(comment);
        const found = resolved.filter((entry) => entry.el);
        return {
            resolved,
            found,
            total: resolved.length,
            degraded: resolved.length > 1 && found.length < resolved.length,
        };
    };

    // Human-readable anchor: the first non-empty line of the element's visible text,
    // truncated. Returns '' when there's nothing readable — callers then show no chip
    // rather than a meaningless CSS selector.
    const firstLineLabel = (raw) => {
        const line =
            String(raw || '')
                .split('\n')
                .map((part) => part.trim())
                .find((part) => part.length > 0) || '';
        if (!line) return '';
        return line.length > 44 ? line.slice(0, 43).trim() + '…' : line;
    };

    // What an anchor is called: the text it quotes, or its element's own text when
    // it quotes nothing. Pass kind 'element' where the widget is drawing the
    // element rather than the quote, so the name matches the box on the page.
    const anchorLabel = (anchor, kind) =>
        firstLineLabel(
            'element' === kind ? anchor.text : anchor.quote || anchor.text,
        );

    // Where a comment was made, as a short path. The reviewer only needs to tell one
    // page from another, so the origin is dropped and the raw string is the fallback
    // when the URL will not parse — a label beats none.
    const pageLabel = (raw) => {
        const value = String(raw || '');
        let label;
        try {
            const parsed = new URL(value, location.href);
            label = parsed.pathname + parsed.search;
        } catch {
            label = value;
        }
        if (!label) return '';
        return label.length > 32 ? label.slice(0, 31).trim() + '…' : label;
    };

    // --- Design tokens: the app's palette under the app's token names, restated as literals. ---
    // The widget is embedded on other people's sites, so it may load nothing from
    // Loupe but itself: no @font-face, and no app font names either, since a
    // visitor's browser has none of them installed. The host's system UI font is
    // the whole type stack.
    //
    // The launcher, the pick-mode toast and the tooltips are the app's near-black
    // chrome in *both* themes. They float over a background the widget does not
    // control, and near-black carries its own contrast where paper does not.
    const CHROME = {
        '--font':
            "ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
        '--mono': 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
        '--chrome': '#181d18',
        '--chrome-raised': '#242a23',
        '--chrome-line': '#343b30',
        '--chrome-fg': '#f0f2eb',
        '--chrome-mute': '#b5bcaf',
        '--danger-on-chrome': '#f08a80',
        '--bar-shadow': '0 6px 20px rgba(24,29,24,.35)',
        '--accent': '#d4e94c',
        '--accent-hover': '#c4dc32',
        // Chartreuse is a light colour, so its foreground stays ink in both themes.
        '--ink': '#20241f',
        // The dark edge that keeps a pin and the picker outline legible over a host
        // page whose background may be any colour, chartreuse-adjacent included.
        // Deep chartreuse rather than ink, so the edge reads as part of the accent
        // instead of a black box drawn over the page.
        '--pin-ring': '#3f4700',
        // Separation for the picker outline and its label. A soft shadow rather
        // than a hard ring: stacked inside the accent border and the fill glow,
        // a third concentric edge muddied the box at small sizes.
        '--pin-shadow': 'rgba(63,71,0,.35)',
        // A pin whose element is gone. Amber in both themes, since it sits on
        // the host page and not on the widget's own surface.
        '--warn-pin': '#e9b949',
        '--warn-pin-ring': '#5c3d00',
    };
    const LIGHT = {
        '--surface-1': '#ffffff',
        '--border': '#e1e5da',
        '--surface-2': '#f7f8f3',
        '--border-faint': '#edf0e8',
        '--text': '#20241f',
        '--text-mute': '#555b52',
        '--text-dim': '#656b60',
        '--bg-sunken': '#f0f2eb',
        '--field': '#fcfdf9',
        '--field-focus': '#edf0e8',
        // Accent dark enough to read as text on paper.
        '--accent-ink': '#596313',
        '--accent-soft': '#edf2ce',
        '--accent-border': '#dce69b',
        '--accent-fill': 'rgba(212,233,76,.22)',
        '--shadow': '0 10px 34px rgba(24,29,24,.22)',
        '--scrim': 'rgba(24,29,24,.28)',
        '--status-ok': '#2f9e5c',
        '--status-danger': '#c2372b',
        '--on-danger': '#ffffff',
        '--warn': '#7a4f00',
        '--warn-soft': '#fbf1d9',
    };
    // The app itself is light-only. These values exist in the widget alone, built
    // from the dark shell rungs, so a widget on a dark host page still reads as Loupe.
    const DARK = {
        '--surface-1': '#242a23',
        '--border': '#343b30',
        '--surface-2': '#2a3029',
        '--border-faint': '#30372e',
        '--text': '#f0f2eb',
        '--text-mute': '#b5bcaf',
        '--text-dim': '#9aa295',
        '--bg-sunken': '#30372e',
        '--field': '#30372e',
        '--field-focus': '#3a4237',
        '--accent-ink': '#d4e94c',
        '--accent-soft': '#2c3010',
        '--accent-border': '#59631a',
        '--accent-fill': 'rgba(212,233,76,.26)',
        '--shadow': '0 12px 36px rgba(0,0,0,.55)',
        '--scrim': 'rgba(0,0,0,.45)',
        '--status-ok': '#4ab97a',
        '--status-danger': '#e4685c',
        '--on-danger': '#20241f',
        '--warn': '#f0b54a',
        '--warn-soft': '#3a3016',
    };

    // Inline SVG icons — this widget is embedded on third-party sites with no access
    // to the app's icon bundle, so the markup must be self-contained.
    const svg = (size, body, stroke) =>
        `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" ` +
        `stroke-width="${stroke || 2}" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`;
    const ICON = {
        comment: (s) =>
            svg(
                s,
                '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
                2,
            ),
        target: (s) =>
            svg(
                s,
                '<circle cx="12" cy="12" r="8"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5"/>',
                2,
            ),
        close: (s) =>
            svg(
                s,
                '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
                2,
            ),
        arrowOut: (s) =>
            svg(
                s,
                '<path d="M7 17 17 7"/><polyline points="8 7 17 7 17 16"/>',
                2,
            ),
        chevron: (s) => svg(s, '<polyline points="6 9 12 15 18 9"/>', 2),
        back: (s) => svg(s, '<polyline points="15 18 9 12 15 6"/>', 2),
        trash: (s) =>
            svg(
                s,
                '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
                2,
            ),
        check: (s, stroke, color) =>
            `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="${color || 'currentColor'}" ` +
            `stroke-width="${stroke || 2.4}" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
        edit: (s) =>
            svg(
                s,
                '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
                2,
            ),
        pen: (s) =>
            svg(
                s,
                '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
                2,
            ),
        more: (s) =>
            svg(
                s,
                '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
                2,
            ),
        alertCircle: (s) =>
            svg(
                s,
                '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
                2,
            ),
        alert: (s) =>
            svg(
                s,
                '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
                2,
            ),
    };

    // ---- launcher corner ----
    // The launcher is fixed to one corner, and a host page may pin its own chrome
    // to that same corner. Both routes out of a collision write here: the panel's
    // move control walks this list, and a drag snaps to the nearest entry.
    const CORNERS = ['bottom-right', 'bottom-left', 'top-left', 'top-right'];
    const CORNER_LABEL = {
        'bottom-right': 'bottom right',
        'bottom-left': 'bottom left',
        'top-left': 'top left',
        'top-right': 'top right',
    };
    const CORNER_KEY = 'loupe.site-review.corner';
    // Reading and writing site data throws outright in a private window, or where
    // the visitor blocks it, and this script runs on other people's pages. Both
    // sides swallow that: the default corner is a correct answer.
    const readCorner = () => {
        try {
            const stored = window.localStorage.getItem(CORNER_KEY);
            if (CORNERS.includes(stored)) return stored;
        } catch {
            /* storage unavailable — fall through to the default */
        }
        return CORNERS[0];
    };
    const writeCorner = (corner) => {
        try {
            window.localStorage.setItem(CORNER_KEY, corner);
        } catch {
            /* storage unavailable — the corner still holds for this page */
        }
    };

    // ---- widget state (the reviewer's comments live in `comments`; this is UI state) ----
    // Comments are identified by their index in `comments`; every mutation re-renders.
    const state = {
        open: false,
        target: false,
        drawing: false, // the freehand canvas is taking pointer events
        // Strokes of the comment being composed, each a list of [x, y] in document
        // pixels. They become fractions at save time, never before, because the
        // page can scroll or the anchor can change between the drag and the save.
        strokes: [],
        // The panel shows one view at a time. `stack` holds the views beneath
        // it, so Back returns to whichever view opened the current one.
        view: 'list', // 'list' | 'compose' | 'finish' | 'destination' | 'signin'
        stack: [],
        // { type:'general' } | { type:'element', anchors: [{ el, selector, text, label }] }
        composeTarget: null,
        draft: '',
        deliveryId: null,
        deliveryTarget: null, // the wire target the delivery id was minted for
        addAnchor: false, // the add-another-element modifier is held down
        modCancelled: false, // another key went down during this hold, so it adds nothing
        editId: null, // server id of the comment being edited in place, or null for a new one
        expandLevel: 0,
        corner: readCorner(), // which corner the launcher and the panel are pinned to
        toastDock: 'top', // 'top' | 'bottom' — the pick-mode toast dodges away from the cursor
        moveHL: null, // { left, top, width, height, label } while picking
        quotePick: null, // { range, anchor } — a live selection offering to be quoted
        hoverId: null, // hovered comment index (list row)
        hoverAnchor: null, // anchor index whose on-page remove control is revealed
        hoverPinId: null, // hovered pin index
        pinConfirmId: null, // armed pin-popover delete
        saving: false,
        deleting: false,
        savedNotice: null, // brief "it is live now" toast text, cleared on a timer
        actionError: null, // failed mutation (network/5xx/429/404): a dismissible inline banner
        fatal: null, // { status } once the token is rejected (401/403) — replaces the panel
    };
    let moveBase = null; // deepest element under the cursor while picking
    let pinCloseTimer = 0;
    let liveStroke = null; // the stroke under the pointer right now, or null

    // A note is being written whenever the composer holds a target, whichever
    // view is on top of it: the destination view opens over a half-written note.
    const composing = () => state.composeTarget !== null;
    // Showing a view that is already beneath the current one returns to it, so
    // two views are never open at once and the stack never holds a repeat.
    const showView = (view) => {
        if (state.view === view) return;
        const at = state.stack.lastIndexOf(view);
        if (at !== -1) {
            state.stack = state.stack.slice(0, at);
        } else {
            state.stack = [...state.stack, state.view];
        }
        state.view = view;
    };
    const backView = () => {
        state.view = state.stack.length ? state.stack.pop() : 'list';
    };
    const resetView = (view = 'list') => {
        state.view = view;
        state.stack = [];
    };

    // --- Shadow-DOM UI host (launcher + panel), isolated from host-page CSS. ---
    const host = document.createElement('div');
    host.style.cssText =
        'position:fixed;inset:0;pointer-events:none;z-index:2147483647';
    const root = host.attachShadow({ mode: 'open' });
    document.documentElement.appendChild(host);

    // --- On-screen overlay (scrim, highlight, pins, instruction toast) in its own
    // shadow host so the host page's stylesheet can't bleed into the markers. ---
    const overlayHost = document.createElement('div');
    overlayHost.style.cssText =
        'position:fixed;inset:0;pointer-events:none;z-index:2147483646';
    const overlayRoot = overlayHost.attachShadow({ mode: 'open' });
    document.documentElement.appendChild(overlayHost);

    // Force a crosshair across the whole page while picking (host elements may set
    // their own cursor; a global rule wins).
    const cursorStyle = document.createElement('style');
    document.documentElement.appendChild(cursorStyle);

    root.innerHTML = `
    <style>
      *{box-sizing:border-box}
      :host{all:initial}
      /* The panel is a fixed-size popover and picking drives off hover, so the
         widget hides rather than degrades: below the app's own mobile boundary,
         and on any touch-primary device regardless of width — an embedder page
         with no viewport meta reports ~980px on a phone, so width alone would
         miss it. A media query, not a boot-time check: it follows a rotation or
         a resize back into view on its own. */
      @media (max-width:639px),(hover:none) and (pointer:coarse){:host{display:none}}
      @keyframes lp-spin{to{transform:rotate(360deg)}}
      @keyframes lp-fade{from{opacity:0}to{opacity:1}}
      @keyframes lp-menu-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
      @keyframes lp-menu-up{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
      @keyframes lp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes lp-pop-down{from{transform:translateY(-8px) scale(.985)}to{transform:none}}
      @keyframes lp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes lp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      button:focus-visible,select:focus-visible,input:focus-visible,textarea:focus-visible,a:focus-visible,[role="button"]:focus-visible{outline:2px solid var(--accent-ink);outline-offset:2px}
      .lp-scroll::-webkit-scrollbar{width:10px;height:10px}
      .lp-scroll::-webkit-scrollbar-thumb{background:var(--text-dim);border-radius:6px;border:3px solid transparent;background-clip:content-box}
      .lp-scroll::-webkit-scrollbar-track{background:transparent}

      .lp-launcher{position:fixed;right:20px;bottom:20px;height:44px;padding:0 4px;display:flex;align-items:center;gap:0;background:var(--chrome);border:1px solid var(--chrome-line);border-radius:999px;box-shadow:var(--bar-shadow);font-family:var(--font);pointer-events:auto;cursor:grab;user-select:none;-webkit-user-select:none;transition:box-shadow .14s ease,background .25s ease}
      /* Corner placement. Only the two offsets move: the launcher keeps one
         shape in every corner, so the open/close collapse is untouched. */
      .lp-launcher.at-left{right:auto;left:20px}
      .lp-launcher.at-top{bottom:auto;top:20px}
      /* While dragging, the launcher follows the pointer on left/top, so both
         corner offsets are cleared inline and the raised shadow says it is loose. */
      .lp-launcher.dragging{cursor:grabbing;box-shadow:0 14px 34px rgba(15,15,13,.5)}
      .lp-launcher.dragging *{cursor:grabbing}
      /* The quick actions collapse as one unit when the panel opens. max-width + opacity
         animate the slide-away; visibility flips to hidden only after the collapse (the
         .24s delay) so the buttons are genuinely non-interactive once gone, and back
         immediately on expand. */
      /* max-width is the collapse animation's start, so it has to clear the row
         and stay near it. Three actions and the divider measure 124px, and the
         travel above that is time the collapse spends going nowhere. */
      .lp-launch-quick{display:flex;align-items:center;gap:2px;overflow:hidden;max-width:140px;opacity:1;visibility:visible;transition:max-width .3s cubic-bezier(.2,.9,.3,1),opacity .2s ease,visibility 0s 0s}
      .lp-launcher.open .lp-launch-quick{max-width:0;opacity:0;visibility:hidden;transition:max-width .3s cubic-bezier(.2,.9,.3,1),opacity .2s ease,visibility 0s .3s}
      .lp-launch-action{flex:0 0 auto;width:36px;height:36px;border:0;background:transparent;color:var(--chrome-mute);border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:color .14s ease}
      .lp-launch-action:hover{color:var(--chrome-fg)}
      .lp-launch-action.on,.lp-launch-action.on:hover{padding:5px;background:var(--accent);background-clip:content-box;color:var(--ink)}
      .lp-launch-dot{flex:0 0 auto;width:8px;height:8px;border-radius:999px;background:var(--chrome-mute)}
      .lp-launch-dot.alert{background:var(--danger-on-chrome)}
      .lp-launch-div{flex:0 0 auto;width:1px;height:20px;background:var(--chrome-line);margin:0 4px}
      /* A label and not a control: the launcher's chrome weight, no hover state
         and no pointer target of its own, so a press still drags the bar. */
      .lp-local{flex:0 0 auto;height:22px;margin:0 2px 0 6px;padding:0 8px;display:inline-flex;align-items:center;background:var(--chrome-raised);border:1px solid var(--chrome-line);border-radius:999px;color:var(--chrome-mute);font-family:inherit;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
      .lp-launch-main{display:flex;align-items:center;gap:8px;height:36px;padding:0 10px 0 12px;background:transparent;border:0;color:var(--chrome-fg);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;border-radius:999px;transition:background .2s}
      .lp-launch-main:hover,.lp-launcher.open .lp-launch-main{background:var(--chrome-raised)}
      /* Styled tooltips for the launcher buttons, above each on hover. */
      [data-tip]{position:relative}
      [data-tip]::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%) translateY(3px);padding:5px 10px;background:var(--chrome-raised);border:1px solid var(--chrome-line);color:var(--chrome-fg);font-size:12px;font-weight:500;line-height:1.4;white-space:nowrap;border-radius:999px;box-shadow:var(--bar-shadow);opacity:0;pointer-events:none;transition:opacity .12s ease,transform .12s ease}
      [data-tip]:hover::after{opacity:1;transform:translateX(-50%) translateY(0)}
      /* A tooltip above a launcher docked at the top would sit off screen, so
         it hangs below instead. The hover rule is restated because the corner
         selector outranks the plain one. */
      .lp-launcher.at-top [data-tip]::after{bottom:auto;top:calc(100% + 8px);transform:translateX(-50%) translateY(-3px)}
      .lp-launcher.at-top [data-tip]:hover::after{transform:translateX(-50%) translateY(0)}
      .lp-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;font-size:11px;font-weight:700;background:var(--accent);color:var(--ink)}

      .lp-panel{position:fixed;right:20px;bottom:80px;width:362px;max-height:min(560px,calc(100vh - 160px));display:flex;flex-direction:column;background:var(--surface-1);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);pointer-events:auto;overflow:hidden;font-family:var(--font);color:var(--text);transition:height .34s cubic-bezier(.2,.9,.3,1),transform .3s cubic-bezier(.2,.9,.3,1),opacity .24s ease,visibility 0s linear 0s,background .25s ease,border-color .25s ease}
      .lp-panel.is-closed{transform:translateY(24px);opacity:0;visibility:hidden;pointer-events:none;transition:height .34s ease,transform .26s ease,opacity .2s ease,visibility 0s linear .26s,background .25s ease,border-color .25s ease}
      /* The panel opens away from the launcher's edge. 80px clears the 44px
         launcher, its 20px inset and a 16px gap, and the 160px max-height
         budget covers that offset at either end, so no corner grows off screen. */
      .lp-panel.at-left{right:auto;left:20px}
      .lp-panel.at-top{bottom:auto;top:80px}
      .lp-panel.at-top.is-closed{transform:translateY(-24px)}
      /* Every view stays mounted and sits over the panel's box. The panel's
         height follows the current view, which JS measures. A view that is
         not current hides with visibility after its slide, so Tab and the
         accessibility tree skip it, and takes it back at once when it returns:
         a focus call right after a switch never waits on the animation. */
      .lp-views{position:relative;flex:1 1 auto;min-height:0;overflow:hidden;overflow:clip}
      .lp-view{position:absolute;left:0;top:0;width:100%;max-height:min(560px,calc(100vh - 160px));display:flex;flex-direction:column;background:var(--surface-1);transition:transform .34s cubic-bezier(.2,.9,.3,1),opacity .34s ease,visibility 0s linear 0s}
      .lp-views.no-slide .lp-view{transition:none!important}
      .lp-view.is-under{transform:translateX(-28%);opacity:0;visibility:hidden;pointer-events:none;transition:transform .34s cubic-bezier(.2,.9,.3,1),opacity .34s ease,visibility 0s linear .34s}
      .lp-view.is-away{transform:translateX(100%);visibility:hidden;pointer-events:none;transition:transform .34s cubic-bezier(.2,.9,.3,1),visibility 0s linear .34s}
      #lp-view-finish{max-height:min(540px,calc(100vh - 160px))}
      @media (prefers-reduced-motion:reduce){.lp-panel,.lp-view,.lp-view.is-under,.lp-view.is-away,.lp-menu{transition:none;animation:none}.lp-panel.is-closed{transition:none}}
      .lp-hd{flex:0 0 auto;display:flex;align-items:flex-start;gap:4px;padding:12px 10px 12px 16px;border-bottom:1px solid var(--border-faint)}
      .lp-hd--back{padding-left:8px}
      .lp-hd--back .lp-hd-text{padding-top:5px}
      .lp-hd-text{flex:1 1 auto;min-width:0}
      .lp-title{min-height:32px;display:flex;align-items:center;font-size:15px;font-weight:600;line-height:20px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-hd-text .lp-title{display:block;min-height:0;margin-top:6px}
      .lp-hd--back .lp-hd-text .lp-title{margin-top:0}
      #lp-destination-head .lp-hd-text{padding-top:0}
      #lp-destination-head .lp-hd-text .lp-title{margin-top:6px}
      .lp-sub{margin-top:2px;font-size:12px;line-height:16px;color:var(--text-mute)}
      .lp-dest{max-width:100%;height:32px;margin-left:-8px;padding:0 6px 0 8px;display:inline-flex;align-items:center;gap:6px;border:0;border-radius:8px;background:transparent;color:var(--text);font:inherit;font-size:15px;font-weight:600;cursor:pointer}
      .lp-dest:hover{background:var(--bg-sunken)}
      .lp-dest span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-dest svg{flex:0 0 auto}
      .lp-body{flex:1 1 auto;min-height:0;overflow:auto;padding-bottom:8px}
      #lp-list{overflow-x:clip}
      .lp-section{display:flex;align-items:center;justify-content:space-between;padding:10px 16px 4px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim)}
      .lp-spacer{flex:1}
      .lp-iconbtn{flex:0 0 auto;width:32px;height:32px;border:0;background:transparent;color:var(--text-mute);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer}
      .lp-iconbtn:hover,.lp-iconbtn[aria-expanded="true"]{background:var(--bg-sunken);color:var(--text)}

      .lp-context{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-context-label{color:inherit;font:inherit}
      .lp-context-label[data-role="picker"]{cursor:pointer;background:none;border:0;padding:0;border-radius:3px}
      .lp-context-label[data-role="picker"]:hover{color:var(--text);text-decoration:underline;text-underline-offset:2px}
      .lp-context-label[data-wrap]{white-space:normal}
      .lp-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
      .lp-dest-body{flex:1 1 auto;min-height:0;overflow:auto;padding:16px}
      #lp-picker-modes{display:flex;flex-direction:column;gap:8px}
      .lp-opt{display:grid;grid-template-columns:20px minmax(0,1fr);gap:10px;align-items:start;padding:12px;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:background .15s,border-color .15s}
      .lp-opt.is-on{border-color:var(--accent-border);background:var(--accent-soft)}
      .lp-opt input[type="radio"]{margin:2px 0 0;width:16px;height:16px;accent-color:#596313}
      .lp-opt-main{min-width:0}
      .lp-opt-main label{cursor:pointer}
      .lp-opt-t{display:block;font-size:13px;font-weight:600;line-height:18px;color:var(--text)}
      .lp-opt-d{display:block;margin-top:2px;font-size:12px;line-height:16px;color:var(--text-mute)}
      .lp-opt-more{cursor:default}
      .lp-seg{display:flex;padding:2px;margin-top:10px;border-radius:8px;background:var(--surface-1);border:1px solid var(--border)}
      .lp-seg button{flex:1;height:28px;border:0;border-radius:6px;background:transparent;color:var(--text-mute);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer}
      .lp-seg button.is-on{background:var(--bg-sunken);color:var(--text)}
      .lp-field-label{display:block;margin:10px 0 4px;font-size:12px;font-weight:600;color:var(--text-mute)}
      .lp-field{display:block;width:100%;height:32px;padding:0 10px;border:1px solid var(--border);border-radius:8px;background:var(--field);color:var(--text);font-family:inherit;font-size:13px}
      .lp-field::placeholder{color:var(--text-dim)}
      .lp-field:focus{outline-color:transparent;border-color:var(--accent-border);box-shadow:0 0 0 3px var(--accent-soft)}
      .lp-field-search{margin-top:10px}
      .lp-opt-error{margin-top:8px;font-size:12px;line-height:16px;color:var(--status-danger)}
      .lp-picker-list{max-height:min(132px,20vh);overflow:auto;margin-top:6px}
      .lp-picker-row{display:flex;align-items:center;gap:6px;width:100%;min-height:32px;padding:0 8px;border:0;border-radius:6px;background:transparent;color:var(--text);font-family:inherit;font-size:13px;text-align:left;cursor:pointer}
      .lp-picker-row:hover{background:var(--bg-sunken)}
      .lp-picker-row.is-picked{background:var(--surface-1);box-shadow:inset 0 0 0 1px var(--accent-border);font-weight:600}
      .lp-picker-row .n{flex:0 0 auto;color:var(--text-mute)}
      .lp-picker-row .t{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-picker-note{padding:6px 8px;font-size:12px;color:var(--text-mute)}
      .lp-dest-foot{flex:0 0 auto;display:flex;gap:8px;padding:12px 16px 16px;border-top:1px solid var(--border-faint);background:var(--surface-2)}
      .lp-dest-foot .lp-ghost{height:40px;font-size:14px}
      .lp-dest-foot .lp-foot-action{flex:1 1 auto;width:auto}
      .lp-dest-foot .lp-foot-action:disabled{opacity:.45;cursor:default}
      .lp-verdict{flex:1 1 auto;min-height:0;overflow:auto;padding:16px;display:flex;flex-direction:column;gap:18px;font-size:12px;line-height:1.45;color:var(--text)}
      .lp-verdict-set{border:0;margin:0;padding:0;min-width:0;display:flex;flex-direction:column;gap:8px}
      .lp-verdict-label{display:block;padding:0;margin-bottom:8px;line-height:13px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim)}
      .lp-verdict-opt{display:grid;grid-template-columns:20px minmax(0,1fr);gap:10px;align-items:start;padding:10px 12px;border:1px solid var(--border);border-radius:10px;cursor:pointer}
      .lp-verdict-opt.is-on{border-color:var(--accent-border);background:var(--accent-soft)}
      .lp-verdict-opt input{margin:2px 0 0;width:16px;height:16px;accent-color:#596313}
      .lp-verdict-opt input:focus-visible{outline:2px solid var(--accent-ink);outline-offset:2px}
      .lp-verdict-opt-t{display:block;font-size:13px;font-weight:600;line-height:18px}
      .lp-verdict-opt-d{display:block;margin-top:2px;line-height:16px;color:var(--text-mute)}
      .lp-verdict-pr{display:flex;align-items:center;gap:6px;overflow-wrap:anywhere}
      .lp-verdict-notes{display:flex;flex-direction:column;gap:8px}
      .lp-verdict-note{display:grid;grid-template-columns:20px minmax(0,1fr);gap:8px;align-items:start;line-height:17px;color:var(--text-mute);overflow-wrap:anywhere}
      .lp-verdict-n{width:20px;height:20px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:700;line-height:1;background:var(--accent);color:var(--ink)}
      .lp-verdict-n.off{background:var(--bg-sunken);color:var(--text-mute)}
      .lp-verdict .lp-textarea{min-height:72px;border:1px solid var(--border);background:var(--field)}
      .lp-verdict-line{line-height:16px;color:var(--text-dim)}
      .lp-verdict-warn{line-height:16px;color:var(--warn)}
      .lp-verdict-error{color:var(--status-danger)}
      .lp-verdict-foot{flex:0 0 auto;display:flex;gap:8px;padding:12px 16px 16px;border-top:1px solid var(--border-faint);background:var(--surface-2)}
      .lp-verdict-foot .lp-foot-action{flex:1 1 auto;width:auto}
      .lp-verdict-foot .lp-ghost{height:40px;font-size:14px}
      .lp-verdict-foot .lp-foot-action:disabled{opacity:.45;cursor:default}
      .lp-verdict-result{color:var(--text-mute)}
      .lp-verdict-result a{color:var(--accent-ink)}
      .lp-composer{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;margin:12px 12px 16px;border:1px solid var(--accent-border);border-radius:12px;background:var(--field);box-shadow:0 0 0 3px var(--accent-soft)}
      .lp-compose-head{display:flex;flex-wrap:wrap;gap:6px;padding:10px 10px 0;max-height:90px;overflow-y:auto;overscroll-behavior:contain}
      .lp-compose-chip{flex:0 1 auto;min-width:0;height:24px;box-sizing:border-box;padding:0 4px 0 8px;display:inline-flex;align-items:center;gap:5px;border-radius:6px;background:var(--bg-sunken);color:var(--text);font-size:12px;white-space:nowrap}
      .lp-compose-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-chip-name{flex:0 1 auto;min-width:0;display:inline-flex;align-items:center;border:0;padding:0;background:transparent;color:inherit;font-family:inherit;font-size:inherit;font-weight:inherit;line-height:1;cursor:pointer}
      .lp-chip-name:focus-visible{outline:2px solid var(--accent-ink);outline-offset:2px;border-radius:4px}
      .lp-compose-chip.lit{background:var(--accent);color:var(--ink)}
      .lp-chip-x{flex:0 0 auto;width:18px;height:18px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:4px;background:transparent;color:var(--text-dim);cursor:pointer}
      .lp-chip-x:hover{background:var(--surface-1);color:var(--text)}
      .lp-compose-chip.lit .lp-chip-x{color:inherit}
      .lp-compose-add{height:24px;box-sizing:border-box;padding:0 8px;display:inline-flex;align-items:center;gap:5px;border:1px dashed var(--border);border-radius:6px;background:transparent;color:var(--text-mute);font-family:inherit;font-size:12px;cursor:pointer}
      .lp-compose-add .lp-kbd{margin-left:2px}
      .lp-compose-add:hover{background:var(--bg-sunken);color:var(--text)}
      .lp-textarea{display:block;width:100%;min-height:72px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--field);color:var(--text);font-family:inherit;font-size:13px;line-height:18px;resize:none}
      .lp-textarea:focus{outline-color:transparent;border-color:var(--accent-border);box-shadow:0 0 0 3px var(--accent-soft)}
      .lp-textarea::placeholder{color:var(--text-dim)}
      .lp-composer .lp-textarea{flex:1 1 auto;border:0;background:transparent}
      .lp-composer .lp-textarea:focus{box-shadow:none}
      .lp-composer .lp-error{margin:0 10px 6px}
      .lp-compose-foot{display:flex;align-items:center;gap:4px;padding:6px 8px 8px 6px}
      .lp-compose-foot .lp-iconbtn.on{background:var(--accent-soft);color:var(--accent-ink)}
      .lp-kbd{height:18px;padding:0 5px;box-sizing:content-box;display:inline-flex;align-items:center;border:1px solid var(--border);border-radius:4px;font-size:11px;font-weight:600;color:var(--text-dim);white-space:nowrap}
      .lp-ghost{height:32px;padding:0 12px;background:transparent;border:0;color:var(--text-mute);font-family:inherit;font-size:13px;font-weight:600;border-radius:10px;cursor:pointer}
      .lp-ghost:hover{background:var(--bg-sunken);color:var(--text)}
      .lp-primary{height:32px;padding:0 12px;display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:var(--ink);border:0;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:background .14s ease}
      .lp-primary:hover{background:var(--accent-hover)}
      .lp-primary[disabled]{opacity:.45;cursor:default}

      /* Three capture modes share one 320px row, so each label is one word and
         the keyboard hints moved to the docs. The full name stays the button's
         accessible name, which is what a screen reader and the specs read. */
      .lp-actions{flex:0 0 auto;display:flex;gap:8px;padding:12px 16px}
      .lp-action{flex:1;min-width:0;height:32px;display:flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer;border:0;background:var(--bg-sunken);color:var(--text);transition:background .15s ease,color .15s ease}
      /* An icon is a flex item with a min-content width of zero, so a row one
         pixel too tight silently squashes it instead of overflowing. */
      .lp-action svg{flex:0 0 auto}
      .lp-action:hover{background:var(--field-focus)}
      /* Pressed, not primary: a solid accent fill here would compete with the
         Save button for the eye, so the toggle takes the pale tint. */
      .lp-action.active{background:var(--accent-soft);color:var(--accent-ink);box-shadow:inset 0 0 0 1px var(--accent-border)}
      .lp-action[disabled]{opacity:.45;cursor:default}
      .lp-action[disabled]:hover{background:var(--bg-sunken)}

      .lp-error{margin:0 14px 10px;padding:9px 11px;display:flex;align-items:flex-start;gap:8px;background:color-mix(in srgb,var(--status-danger) 10%,transparent);border:1px solid color-mix(in srgb,var(--status-danger) 28%,transparent);border-radius:10px;font-size:12px;line-height:1.45;color:var(--status-danger)}
      .lp-error span{flex:1;padding-top:2px}
      .lp-error button{flex:0 0 auto;background:transparent;border:0;color:var(--status-danger);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;border-radius:10px;padding:3px 9px}
      .lp-error button:hover{background:color-mix(in srgb,var(--status-danger) 14%,transparent)}

      .lp-empty{min-height:132px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px;gap:6px;color:var(--text-mute);font-size:13px}
      .lp-empty-title{font-weight:600;color:var(--text)}
      @keyframes lp-row-in{from{opacity:0;transform:translateX(12px)}to{opacity:1;transform:none}}
      .lp-item{display:flex;gap:10px;padding:10px 12px 10px 16px;cursor:default;animation:lp-row-in .3s ease}
      @media (prefers-reduced-motion:reduce){.lp-item{animation:none}}
      .lp-item:hover{background:var(--surface-2)}
      .lp-badge{flex:0 0 auto;width:24px;height:24px;border-radius:999px;box-sizing:border-box;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
      .lp-badge.element{background:var(--accent);color:var(--ink)}
      .lp-badge.general{border:1.5px dashed var(--text-dim);color:var(--text-dim)}
      .lp-badge.off{background:var(--bg-sunken);color:var(--text-mute)}
      .lp-item-body{flex:1;min-width:0}
      .lp-item-text{font-size:13px;line-height:18px;color:var(--text);word-break:break-word}
      .lp-item-meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:6px}
      .lp-chip{display:inline-flex;align-items:center;gap:5px;height:22px;padding:0 8px;background:var(--bg-sunken);color:var(--text-mute);border-radius:6px;font-size:12px;font-weight:400;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-chip svg{flex:0 0 auto}
      .lp-chip.degraded{background:var(--warn-soft);color:var(--warn)}
      /* Chip-shaped but a real button: it says which page the comment was made on
         and goes there. Only rendered when that page is not the current one. */
      .lp-item-page{display:inline-flex;align-items:center;gap:5px;height:22px;padding:0 8px;background:transparent;border:1px solid var(--border);color:var(--accent-ink);border-radius:6px;font-family:inherit;font-size:12px;font-weight:600;max-width:100%;cursor:pointer}
      .lp-item-page:hover{background:var(--bg-sunken)}
      .lp-item-page svg{flex:0 0 auto}
      .lp-item-page-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-row-menu-btn{flex:0 0 auto;width:24px;height:24px;border:0;background:transparent;color:var(--text-mute);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer}
      .lp-row-menu-btn:hover,.lp-row-menu-btn[aria-expanded="true"]{background:var(--bg-sunken);color:var(--text)}
      .lp-menu{position:fixed;z-index:2;width:max-content;min-width:200px;max-width:calc(100vw - 8px);padding:4px;display:flex;flex-direction:column;background:var(--surface-1);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);font-family:var(--font);pointer-events:auto;animation:lp-menu-in .18s ease}
      .lp-menu.up{animation-name:lp-menu-up}
      .lp-menu-item{height:32px;padding:0 10px;display:flex;align-items:center;white-space:nowrap;border:0;border-radius:6px;background:transparent;color:var(--text);font-family:inherit;font-size:13px;text-align:left;cursor:pointer}
      .lp-menu-item:hover,.lp-menu-item:focus-visible{background:var(--bg-sunken)}
      .lp-menu-item[disabled]{opacity:.55;cursor:default}
      .lp-menu-item.danger{color:var(--status-danger)}
      .lp-menu-sep{height:1px;margin:4px 6px;background:var(--border-faint)}
      .lp-menu-conf{padding:8px 10px 6px;font-size:13px;line-height:18px;color:var(--text)}
      .lp-menu-conf-row{display:flex;gap:6px;padding:4px 6px 6px}
      .lp-ghost-sm{height:32px;padding:0 12px;background:transparent;border:0;color:var(--text-mute);font-family:inherit;font-size:13px;font-weight:600;border-radius:10px;cursor:pointer}
      .lp-ghost-sm:hover{background:var(--bg-sunken);color:var(--text)}

      .lp-footer{flex:0 0 auto;gap:0;padding:12px 16px 16px;border-top:1px solid var(--border-faint)}
      .lp-footer{display:flex;flex-direction:column;background:var(--surface-2)}
      .lp-foot-action{width:100%;height:40px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:10px;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;box-sizing:border-box}
      .lp-foot-action.primary{background:var(--accent);color:var(--ink)}
      .lp-foot-action.primary:hover{background:var(--accent-hover)}
      .lp-foot-action.secondary{height:32px;font-size:13px;background:var(--bg-sunken);color:var(--text)}
      .lp-foot-action.secondary:hover{background:var(--field-focus)}
      .lp-clear-cancel{height:32px;padding:0 12px;background:transparent;border:0;color:var(--text-mute);font-family:inherit;font-size:13px;font-weight:600;border-radius:10px;cursor:pointer}
      .lp-clear-cancel:hover{background:var(--bg-sunken);color:var(--text)}
      .lp-clear-yes{flex:1;height:32px;padding:0 12px;background:var(--status-danger);color:var(--on-danger);border:0;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer}
      .lp-spin{width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;display:inline-block;animation:lp-spin .6s linear infinite}

      .lp-fatal{padding:10px 24px 26px;text-align:center;animation:lp-pop .2s ease}
      .lp-fatal-disc{width:44px;height:44px;margin:6px auto 13px;border-radius:50%;background:color-mix(in srgb,var(--status-danger) 13%,transparent);display:flex;align-items:center;justify-content:center;color:var(--status-danger)}
      .lp-fatal-title{font-size:15px;font-weight:600;color:var(--text)}
      .lp-fatal-sub{max-width:252px;margin:6px auto 0;font-size:12px;color:var(--text-mute);line-height:1.55}
      .lp-signin .lp-fatal-disc{background:color-mix(in srgb,var(--accent) 13%,transparent);color:var(--accent)}
      .lp-signin-body{display:flex;flex-direction:column;gap:14px;padding:20px 16px 16px}
      .lp-step{display:grid;grid-template-columns:24px minmax(0,1fr);gap:10px;font-size:13px;line-height:18px;color:var(--text-mute)}
      .lp-step-n{width:24px;height:24px;border-radius:999px;background:var(--accent-soft);color:var(--accent-ink);display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
      .lp-signin-note{font-size:12px;line-height:16px;color:var(--text-mute)}
      .lp-signin-body .lp-foot-action{margin-top:6px}
    </style>
    <div class="lp-launcher" id="lp-launcher">
      ${LOCAL ? '<span class="lp-local" id="lp-local" data-tip="Notes save to this local build">Local</span>' : ''}
      <div class="lp-launch-quick" id="lp-launch-quick">
        <button class="lp-launch-action" id="lp-launch-note" aria-label="Add note" aria-pressed="false" data-tip="Add note">${ICON.comment(18)}</button>
        <button class="lp-launch-action" id="lp-launch-target" aria-label="Pick element" aria-pressed="false" data-tip="Pick element">${ICON.target(18)}</button>
        <button class="lp-launch-action" id="lp-launch-draw" aria-label="Draw" aria-pressed="false" data-tip="Draw">${ICON.pen(18)}</button>
        <span class="lp-launch-div"></span>
      </div>
      <button class="lp-launch-main" id="lp-launch-main" aria-label="Review">
        <span class="lp-launch-dot" id="lp-launch-dot" style="display:none"></span>
        <span class="lp-launch-dot alert" id="lp-launch-alert" style="display:none" title="This review widget can’t connect"></span>
        <span id="lp-launch-label">Review</span>
        <span class="lp-count" id="lp-launch-count" style="display:none">0</span>
      </button>
    </div>
    <div class="lp-panel is-closed" id="lp-panel" inert>
      <div class="lp-views" id="lp-views">
        <section class="lp-view" id="lp-view-list" data-view="list" aria-label="Review">
          <header class="lp-hd">
            <div class="lp-hd-text">
              <div class="lp-title" id="lp-title">Review</div>
              <button type="button" class="lp-dest" id="lp-dest" aria-label="Change where notes go" style="display:none"><span id="lp-dest-text"></span>${ICON.chevron(16)}</button>
              <div class="lp-sub" id="lp-sub" style="display:none"></div>
            </div>
            <button class="lp-iconbtn" id="lp-head-menu" aria-haspopup="menu" aria-expanded="false" aria-label="More actions">${ICON.more(18)}</button>
            <button class="lp-iconbtn" id="lp-close" aria-label="Close">${ICON.close(18)}</button>
          </header>
          <div class="lp-actions">
            <button class="lp-action" id="general" aria-pressed="false" aria-label="Add note">${ICON.comment(15)}<span>Note</span></button>
            <button class="lp-action" id="target" aria-pressed="false" aria-label="Pick element">${ICON.target(15)}<span>Element</span></button>
            <button class="lp-action" id="draw" aria-pressed="false" aria-label="Draw">${ICON.pen(15)}<span>Draw</span></button>
          </div>
          <div class="lp-error" id="lp-list-error" style="display:none"></div>
          <div class="lp-body lp-scroll" id="lp-body">
            <div class="lp-empty" id="lp-empty" style="display:none">
              <span class="lp-empty-title">No notes yet</span>
              <span class="lp-empty-sub">Pick an element, draw, or write a note.</span>
            </div>
            <div id="lp-list"></div>
          </div>
          <div class="lp-footer" id="lp-footer" style="display:none">
            <div id="lp-foot-action"></div>
          </div>
        </section>
        <section class="lp-view" id="lp-view-compose" data-view="compose" aria-label="Note">
          <header class="lp-hd lp-hd--back">
            <button class="lp-iconbtn" id="lp-compose-back" aria-label="Back to notes">${ICON.back(18)}</button>
            <div class="lp-hd-text">
              <div class="lp-title" id="lp-compose-title">New note</div>
              <div class="lp-sub lp-context" id="lp-context"></div>
            </div>
          </header>
          <div class="lp-composer" id="lp-composer">
            <div class="lp-compose-head" id="lp-compose-head" style="display:none"></div>
            <label class="lp-sr-only" for="lp-textarea">Note</label>
            <textarea class="lp-textarea" id="lp-textarea" placeholder="What should change?"></textarea>
            <div class="lp-error" id="lp-error" style="display:none"></div>
            <div class="lp-compose-foot">
              <button class="lp-iconbtn" id="lp-compose-target" aria-pressed="false" aria-label="Pick element" title="Pick an element  T">${ICON.target(16)}</button>
              <button class="lp-iconbtn" id="lp-compose-draw" aria-pressed="false" aria-label="Draw" title="Draw on the page  D">${ICON.pen(16)}</button>
              <div class="lp-spacer"></div>
              <span class="lp-kbd">${MOD_LABEL} ↵</span>
              <button class="lp-ghost" id="lp-cancel">Cancel</button>
              <button class="lp-primary" id="lp-save" disabled>Save note</button>
            </div>
          </div>
        </section>
        <section class="lp-view" id="lp-view-destination" data-view="destination" aria-label="Where notes go">
          <header class="lp-hd" id="lp-destination-head">
            <button class="lp-iconbtn" id="lp-destination-back" aria-label="Back to notes" style="display:none">${ICON.back(18)}</button>
            <div class="lp-hd-text"><div class="lp-title" id="lp-destination-title">Where should your notes go?</div></div>
            <button class="lp-iconbtn" id="lp-destination-close" aria-label="Close">${ICON.close(18)}</button>
          </header>
          <div class="lp-error" id="lp-destination-error" style="display:none"></div>
          <div class="lp-dest-body lp-scroll" id="lp-picker">
            <div id="lp-picker-modes" role="radiogroup" aria-label="Where notes go">
              <div class="lp-opt" data-mode="per-review">
                <input type="radio" name="lp-dest" id="lp-dest-per-review" value="per-review">
                <div class="lp-opt-main">
                  <label for="lp-dest-per-review"><span class="lp-opt-t">One card for this review</span><span class="lp-opt-d">Every note lands on the same card.</span></label>
                  <div class="lp-opt-more" id="lp-picker-slot-per-review"></div>
                </div>
              </div>
              <div class="lp-opt" data-mode="per-note">
                <input type="radio" name="lp-dest" id="lp-dest-per-note" value="per-note">
                <div class="lp-opt-main">
                  <label for="lp-dest-per-note"><span class="lp-opt-t">A new card for each note</span><span class="lp-opt-d">Good for unrelated issues across the site.</span></label>
                </div>
              </div>
              <div class="lp-opt" data-mode="epic">
                <input type="radio" name="lp-dest" id="lp-dest-epic" value="epic">
                <div class="lp-opt-main">
                  <label for="lp-dest-epic"><span class="lp-opt-t">A card for each note, under an epic</span><span class="lp-opt-d">You pick or create the epic next.</span></label>
                </div>
              </div>
            </div>
            <div id="lp-picker-slot-epic"></div>
            <div class="lp-picker-more" id="lp-picker-more" style="display:none">
              <div class="lp-seg" id="lp-picker-seg" role="group" aria-label="Card">
                <button type="button" data-sub="new" aria-pressed="true"></button>
                <button type="button" data-sub="existing" aria-pressed="false"></button>
              </div>
              <div id="lp-picker-new">
                <label class="lp-field-label" for="lp-picker-title" id="lp-picker-title-label">Card title</label>
                <input class="lp-field" id="lp-picker-title" autocomplete="off">
                <label class="lp-field-label" for="lp-picker-type" id="lp-picker-type-label" style="display:none">Type</label>
                <select class="lp-field" id="lp-picker-type" style="display:none"></select>
                <div class="lp-opt-error" id="lp-picker-error" role="alert" style="display:none"></div>
              </div>
              <div id="lp-picker-cards" style="display:none">
                <input class="lp-field lp-field-search" id="lp-picker-search" placeholder="Search cards…" aria-label="Search cards" autocomplete="off">
                <div class="lp-picker-list" id="lp-picker-list"></div>
              </div>
            </div>
          </div>
          <footer class="lp-dest-foot">
            <button type="button" class="lp-ghost" id="lp-picker-cancel">Cancel</button>
            <button type="button" class="lp-foot-action primary" id="lp-destination-save">Start reviewing</button>
          </footer>
        </section>
        <section class="lp-view" id="lp-view-finish" data-view="finish" aria-label="Finish review">
          <header class="lp-hd lp-hd--back">
            <button class="lp-iconbtn" id="lp-finish-back" aria-label="Back to notes">${ICON.back(18)}</button>
            <div class="lp-hd-text"><div class="lp-title">Finish review</div><div class="lp-sub" id="lp-finish-sub"></div></div>
          </header>
          <div class="lp-verdict lp-scroll" id="lp-verdict"></div>
          <footer class="lp-verdict-foot" id="lp-verdict-foot"></footer>
        </section>
        <section class="lp-view" id="lp-view-signin" data-view="signin" aria-label="Sign in">
          <header class="lp-hd">
            <div class="lp-hd-text"><div class="lp-title">Review this page</div></div>
            <button class="lp-iconbtn" id="lp-signin-close" aria-label="Close">${ICON.close(18)}</button>
          </header>
          <div class="lp-signin-body">
            <div class="lp-step"><span class="lp-step-n">1</span><span>Point at anything on the page and write a note.</span></div>
            <div class="lp-step"><span class="lp-step-n">2</span><span>Each note saves at once to a card on your Loupe board.</span></div>
            <div class="lp-step"><span class="lp-step-n">3</span><span>Your agent picks the notes up and fixes them.</span></div>
            <div class="lp-signin-note" id="lp-signin-note" role="status" style="display:none"></div>
            <button class="lp-foot-action primary" id="lp-sign-in" type="button">Sign in with Loupe</button>
          </div>
        </section>
      </div>
      <div id="lp-fatal" style="display:none"></div>
    </div>
    <div class="lp-menu" id="lp-menu" role="menu" style="display:none"></div>`;

    overlayRoot.innerHTML = `
    <style>
      *{box-sizing:border-box}
      :host{all:initial}
      /* The panel is a fixed-size popover and picking drives off hover, so the
         widget hides rather than degrades: below the app's own mobile boundary,
         and on any touch-primary device regardless of width — an embedder page
         with no viewport meta reports ~980px on a phone, so width alone would
         miss it. A media query, not a boot-time check: it follows a rotation or
         a resize back into view on its own. */
      @media (max-width:639px),(hover:none) and (pointer:coarse){:host{display:none}}
      @keyframes lp-fade{from{opacity:0}to{opacity:1}}
      @keyframes lp-slide-left{from{transform:translateX(-100%)}to{transform:translateX(0)}}
      @keyframes lp-slide-left-out{from{transform:translateX(0)}to{transform:translateX(-100%)}}
      @keyframes lp-pin{from{transform:translateY(-8px);opacity:0}to{transform:none;opacity:1}}
      @keyframes lp-pop{from{transform:translateY(8px) scale(.985)}to{transform:none}}
      @keyframes lp-spin{to{transform:rotate(360deg)}}
      .lp-ov{font-family:var(--font)}
      button:focus-visible,select:focus-visible,input:focus-visible,textarea:focus-visible,a:focus-visible,[role="button"]:focus-visible{outline:2px solid var(--accent-ink);outline-offset:2px}
      .lp-scrim{position:fixed;inset:0;background:var(--scrim);animation:lp-fade .18s ease;pointer-events:none}
      /* Chartreuse alone can vanish against a pale or yellowish host page, so
         every marker the widget paints onto the page carries a near-black edge
         of its own: the accent reads as the brand, the ring keeps it visible. */
      .highlight{position:fixed;border:2px solid var(--accent);background:var(--accent-fill);border-radius:10px;pointer-events:none;box-shadow:0 2px 10px var(--pin-shadow);transition:left .07s ease,top .07s ease,width .07s ease,height .07s ease;z-index:2}
      /* A comment's other anchors. Same accent, no fill, so the framed one still
         reads as the one under the cursor. */
      .highlight.sib{background:transparent;border-width:1.5px;box-shadow:none;transition:none}
      /* The anchor whose pill is pointed at, or whose own box is. It stays the
         emphasised member of a set that all stays painted. */
      .highlight.lit{background:var(--accent-fill);border-width:3px;border-color:var(--accent-ink);box-shadow:0 2px 10px var(--pin-shadow)}
      /* Only a removable box takes pointer events, so an outline never eats a
         click on the host page outside the short window a comment is composed in. */
      .highlight.removable{pointer-events:auto}
      /* The controls live above every box rather than inside one. Two anchors on
         neighbouring elements have overlapping boxes, and a control drawn inside
         its own box would sit under its neighbour and refuse the click. */
      .lp-hl-x{position:fixed;z-index:5;display:flex;width:24px;height:24px;align-items:center;justify-content:center;padding:0;background:var(--accent);color:var(--ink);border:2px solid var(--pin-ring);border-radius:50%;font-family:inherit;font-size:13px;font-weight:700;line-height:1;cursor:pointer;pointer-events:auto;opacity:0;transition:opacity .12s ease;box-shadow:0 2px 8px var(--pin-shadow)}
      /* :focus reveals it, because a mousedown on it is prevented and cannot
         focus it. The ring is :focus-visible, so only a keyboard user sees one. */
      .lp-hl-x.lit,.lp-hl-x:hover,.lp-hl-x:focus{opacity:1}
      .lp-hl-x:focus-visible{outline:2px solid var(--pin-ring);outline-offset:2px}
      .lp-hl-label{position:absolute;left:-2px;top:-30px;display:inline-block;max-width:240px;height:24px;line-height:24px;padding:0 9px;background:var(--accent);color:var(--ink);font-size:11px;font-weight:600;border-radius:999px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-shadow:0 2px 10px var(--pin-shadow)}
      /* Above the outlines, below the pins: the drawing is content over the
         page, and a pin still has to be reachable through it. */
      .lp-canvas{position:fixed;left:0;top:0;z-index:3;pointer-events:none}
      /* touch-action, so a pen or a touch drag on a hybrid laptop reaches
         pointermove instead of being claimed by panning, which would cancel
         the stroke. A touch-primary device hides the widget outright. */
      .lp-ov.drawing .lp-canvas{pointer-events:auto;cursor:crosshair;touch-action:none}
      .lp-pin-wrap{position:fixed;z-index:4;pointer-events:auto}
      .lp-ov.targeting .lp-pin-wrap,.lp-ov.drawing .lp-pin-wrap{pointer-events:none}
      .pin{width:26px;height:26px;padding:0;border-radius:999px 999px 999px 4px;border:1.5px solid var(--pin-ring);background:var(--accent);color:var(--ink);font-family:inherit;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 6px var(--pin-shadow);animation:lp-pin .3s cubic-bezier(.2,.9,.3,1)}
      .pin.act{outline:3px solid var(--accent);outline-offset:2px}
      .pin.degraded{background:var(--warn-pin);border-style:dashed;border-color:var(--warn-pin-ring);color:#20241f}
      .lp-pop{position:absolute;top:18px;right:0;width:280px;padding-top:14px;cursor:default}
      .lp-pop-card{position:relative;background:var(--surface-1);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);animation:lp-fade .12s ease}
      .lp-pop-main{padding:12px 14px 10px}
      .lp-pop-body{font-size:13px;line-height:18px;color:var(--text);word-break:break-word}
      .lp-pop-meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px}
      .lp-pop-chip{display:inline-flex;align-items:center;gap:5px;height:22px;max-width:100%;padding:0 8px;background:var(--bg-sunken);color:var(--text-mute);border-radius:6px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .lp-pop-degraded{display:flex;gap:8px;align-items:flex-start;margin:0 14px 10px;padding:8px 10px;border-radius:8px;background:var(--warn-soft);color:var(--warn);font-size:12px;line-height:16px;word-break:break-word}
      .lp-pop-degraded svg{flex:0 0 auto;margin-top:1px}
      .lp-pop-foot{display:flex;align-items:center;gap:6px;padding:8px 10px;border-top:1px solid var(--border-faint);border-radius:0 0 11px 11px;background:var(--surface-2)}
      .lp-pop-edit{height:28px;padding:0 10px;border:0;border-radius:8px;background:var(--bg-sunken);color:var(--text);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer}
      .lp-pop-edit:hover{background:var(--field-focus)}
      .lp-pop-more{flex:0 0 auto;width:28px;height:28px;padding:0;border:0;border-radius:8px;background:transparent;color:var(--text-mute);display:flex;align-items:center;justify-content:center;cursor:pointer}
      .lp-pop-more:hover,.lp-pop-more[aria-expanded="true"]{background:var(--bg-sunken);color:var(--text)}
      @keyframes lp-menu-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
      .lp-pop-menu{position:absolute;right:10px;bottom:46px;z-index:2;width:168px;padding:4px;display:flex;flex-direction:column;background:var(--surface-1);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);animation:lp-menu-in .18s ease}
      .lp-pop-menu[hidden]{display:none}
      .lp-pop-menu-item{height:32px;padding:0 10px;display:flex;align-items:center;border:0;border-radius:6px;background:transparent;color:var(--text);font-family:inherit;font-size:13px;text-align:left;cursor:pointer}
      .lp-pop-menu-item:hover{background:var(--bg-sunken)}
      .lp-pop-menu-item.danger{color:var(--status-danger)}
      .lp-pop-menu-sep{height:1px;margin:4px 6px;background:var(--border-faint)}
      .lp-pop-confirm{position:absolute;inset:0;background:var(--surface-1);border-radius:11px;padding:12px 14px;display:flex;flex-direction:column;justify-content:center;gap:10px;animation:lp-slide-left .18s cubic-bezier(.4,0,.2,1)}
      .lp-pop-confirm-title{font-size:13px;line-height:18px;color:var(--text)}
      .lp-pop-confirm-row{display:flex;gap:6px}
      .lp-pop-yes{flex:1;height:32px;background:var(--status-danger);color:var(--on-danger);border:0;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer}
      .lp-pop-no{height:32px;padding:0 12px;background:transparent;border:0;color:var(--text-mute);border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer}
      .lp-pop-no:hover{background:var(--bg-sunken);color:var(--text)}
      .lp-toast{position:fixed;top:20px;left:50%;--lp-dock:0px;--lp-hide:-24px;transform:translate(-50%,calc(var(--lp-dock) + var(--lp-hide)));opacity:0;visibility:hidden;z-index:6;display:flex;align-items:center;gap:10px;height:40px;padding:0 6px 0 14px;background:var(--chrome);border:1px solid var(--chrome-line);color:var(--chrome-fg);border-radius:999px;font-size:13px;font-weight:500;white-space:nowrap;box-shadow:var(--bar-shadow);transition:transform .32s cubic-bezier(.2,.9,.3,1),opacity .24s ease,visibility 0s linear .32s;pointer-events:none}
      .lp-toast.on{transform:translate(-50%,var(--lp-dock));opacity:1;visibility:visible;pointer-events:auto;transition:transform .32s cubic-bezier(.2,.9,.3,1),opacity .24s ease,visibility 0s linear 0s}
      @media (prefers-reduced-motion:reduce){.lp-toast,.lp-toast.on{transition:none}}
      .lp-toast-sep{color:var(--chrome-line)}
      .lp-toast-dim{color:var(--chrome-mute);font-size:13px;font-weight:500}
      .lp-toast-key{height:28px;padding:0 10px;display:inline-flex;align-items:center;background:var(--chrome-raised);border:0;border-radius:999px;color:inherit;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:background .12s ease}
      .lp-toast-key:hover{background:var(--chrome-line)}
      .lp-toast-key[disabled]{opacity:.45;cursor:default}
      .lp-toast-key[disabled]:hover{background:var(--chrome-raised)}
      .lp-toast--saved{top:72px;padding:0 14px 0 10px}
      .lp-tick{width:20px;height:20px;display:flex;align-items:center;justify-content:center;border-radius:999px;background:var(--accent);color:var(--ink)}
      #lp-draw-done{background:var(--accent);color:var(--ink)}
      #lp-draw-done:hover{background:var(--accent-hover)}
      /* The offer to quote a selection. Same bar furniture as the toasts, so it
         reads as the widget rather than as host-page chrome. */
      .lp-quote-btn{position:fixed;z-index:6;display:inline-flex;align-items:center;gap:10px;height:40px;padding:0 14px;background:var(--chrome);border:1px solid var(--chrome-line);color:var(--chrome-fg);border-radius:999px;font-family:var(--font);font-size:13px;font-weight:500;white-space:nowrap;cursor:pointer;pointer-events:auto;box-shadow:var(--bar-shadow);animation:lp-fade .12s ease}
      .lp-quote-btn:hover{background:var(--chrome-raised)}
      .lp-quote-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
    </style>
    <div class="lp-ov" id="lp-ov">
      <div class="lp-scrim" id="lp-scrim" style="display:none"></div>
      <div id="lp-hls"></div>
      <div class="highlight" id="lp-hl" style="display:none"><span class="lp-hl-label" id="lp-hl-label" style="display:none"></span></div>
      <canvas class="lp-canvas" id="lp-canvas"></canvas>
      <div id="lp-hlx"></div>
      <div id="lp-pins"></div>
      <div class="lp-toast" id="lp-toast">
        ${ICON.target(15)}
        <span id="lp-toast-text">Click to add a note</span>
        <span class="lp-toast-sep">·</span>
        <span class="lp-toast-dim">${ALT_LABEL} scroll to resize</span>
        <span class="lp-toast-key" id="lp-toast-esc">Esc</span>
      </div>
      <div class="lp-toast" id="lp-draw-toast">
        ${ICON.pen(15)}
        <span id="lp-draw-text">Drag to draw</span>
        <span class="lp-toast-sep">·</span>
        <button class="lp-toast-key" id="lp-draw-undo" type="button">Undo</button>
        <button class="lp-toast-key" id="lp-draw-clear" type="button">Clear</button>
        <button class="lp-toast-key" id="lp-draw-done" type="button">Done</button>
      </div>
      <div class="lp-toast lp-toast--saved" id="lp-saved">
        <span class="lp-tick">${ICON.check(12, 3)}</span>
        <span id="lp-saved-text"></span>
      </div>
      <button class="lp-quote-btn" id="lp-quote-btn" type="button" style="display:none">
        ${ICON.comment(15)}<span>Note on this text</span>
      </button>
    </div>`;

    const $ = (id) => root.getElementById(id);
    const $$ = (id) => overlayRoot.getElementById(id);

    const ovRoot = $$('lp-ov');
    const scrimNode = $$('lp-scrim');
    const hlsNode = $$('lp-hls');
    const hlNode = $$('lp-hl');
    const hlLabel = $$('lp-hl-label');
    const hlxNode = $$('lp-hlx');
    const pinsNode = $$('lp-pins');
    const canvasNode = $$('lp-canvas');
    const drawToastNode = $$('lp-draw-toast');
    const toastNode = $$('lp-toast');
    const toastText = $$('lp-toast-text');
    const savedToastNode = $$('lp-saved');
    const quoteBtn = $$('lp-quote-btn');
    const panelNode = $('lp-panel');
    const viewsNode = $('lp-views');
    const viewNodes = {
        list: $('lp-view-list'),
        compose: $('lp-view-compose'),
        destination: $('lp-view-destination'),
        finish: $('lp-view-finish'),
        signin: $('lp-view-signin'),
    };
    const fatalNode = $('lp-fatal');
    const titleNode = $('lp-title');
    const destNode = $('lp-dest');
    const subNode = $('lp-sub');
    const contextNode = $('lp-context');
    const pickerSearchNode = $('lp-picker-search');
    const pickerListNode = $('lp-picker-list');
    const pickerTitleNode = $('lp-picker-title');
    const pickerTypeNode = $('lp-picker-type');
    const verdictNode = $('lp-verdict');
    const verdictFoot = $('lp-verdict-foot');
    const finishSub = $('lp-finish-sub');
    const composeHead = $('lp-compose-head');
    let composeHeadHtml = null;
    const textareaNode = $('lp-textarea');
    const errorNodes = {
        list: $('lp-list-error'),
        compose: $('lp-error'),
        destination: $('lp-destination-error'),
    };
    const emptyNode = $('lp-empty');
    const launchQuick = $('lp-launch-quick');
    const launchDrawBtn = $('lp-launch-draw');
    const listNode = $('lp-list');
    const footerNode = $('lp-footer');
    const footAction = $('lp-foot-action');
    const menuNode = $('lp-menu');
    const headMenuBtn = $('lp-head-menu');
    const saveBtn = $('lp-save');
    const generalBtn = $('general');
    const targetBtns = [$('target'), $('lp-compose-target')];
    const drawBtns = [$('draw'), $('lp-compose-draw')];

    // --- theming: follow the host's color scheme and live-update on change. ---
    const applyTheme = (dark) => {
        const tokens = { ...CHROME, ...(dark ? DARK : LIGHT) };
        [host, overlayHost].forEach((node) => {
            Object.entries(tokens).forEach(([key, value]) =>
                node.style.setProperty(key, value),
            );
        });
    };
    const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    applyTheme(colorScheme.matches);
    colorScheme.addEventListener('change', (event) =>
        applyTheme(event.matches),
    );

    // ---- geometry helpers (viewport-relative; the overlay host is fixed full-screen) ----
    const insideWidget = (el) => host.contains(el) || overlayHost.contains(el);
    const pickEl = (event) => {
        const el = document.elementFromPoint(event.clientX, event.clientY);
        if (!el || el === host || el === overlayHost || insideWidget(el))
            return null;
        if (el === document.body || el === document.documentElement)
            return null;
        return el;
    };
    const climbUp = (el, level) => {
        let current = el;
        for (let i = 0; i < level; i++) {
            const parent = current.parentElement;
            if (
                !parent ||
                parent === document.body ||
                parent === document.documentElement ||
                insideWidget(parent)
            ) {
                break;
            }
            current = parent;
        }
        return current;
    };
    // An element or a Range — both answer getBoundingClientRect, and a quoted
    // anchor is measured on its run of text rather than on its element. A range
    // over several lines gives one box spanning them.
    const rectOf = (box) => {
        const r = box.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) return null;
        return r;
    };
    // What an anchor is drawn around: its quoted text when the quote still reads
    // on the page, and its element when it does not.
    const boxOf = (entry) => entry.range || entry.el;
    const anchorKind = (entry) => (entry.range ? 'quote' : 'element');

    // ---- freehand strokes ----
    // Strokes are held in document pixels while they are drawn and stored as
    // fractions. Document pixels are the only space that survives a scroll
    // between the drag and the save, and a fraction is the only one that survives
    // the page moving under a stroke that is already saved.
    const docPointOf = (event) => [
        event.clientX + window.scrollX,
        event.clientY + window.scrollY,
    ];
    const docRectOf = (el) => {
        const r = el && rectOf(el);
        if (!r) return null;
        return {
            left: r.left + window.scrollX,
            top: r.top + window.scrollY,
            width: r.width,
            height: r.height,
        };
    };
    // Both axes divide by the width, so a page-space drawing keeps its shape. A
    // page taller than it is wide simply carries fractions above 1.
    const pageScale = () => Math.max(document.documentElement.scrollWidth, 1);
    // The box a stroke is measured against: anchor 0 of the comment, matching the
    // pin the reviewer sees first. A zero-sized box divides by nothing.
    const strokeBoxOf = (anchors) => {
        const first = anchors[0];
        const box =
            first &&
            docRectOf(
                first.el ||
                    (first.selector
                        ? queryAnchor(first.selector, first.text)
                        : null),
            );
        return box && box.width > 0 && box.height > 0 ? box : null;
    };
    // How far outside its box a stroke may reach and still be measured against
    // it. A drawing that stays near the element wants to move with it. One that
    // crosses the page from a small element does not: every fraction is then
    // multiplied by a tiny width, so a few pixels of reflow would fling the far
    // end of the stroke across the screen. Page coordinates are the honest space
    // for a page-scale gesture, and they keep the stored numbers small.
    const ANCHOR_SPACE_REACH = 20;
    const storeStroke = (points, box) => {
        const anchored = box
            ? points.map(([x, y]) => [
                  (x - box.left) / box.width,
                  (y - box.top) / box.height,
              ])
            : null;
        if (
            anchored &&
            anchored.every(
                ([x, y]) =>
                    Math.max(Math.abs(x), Math.abs(y)) <= ANCHOR_SPACE_REACH,
            )
        ) {
            return {
                space: 'anchor',
                points: anchored.map(([x, y]) => [
                    Number(x.toFixed(5)),
                    Number(y.toFixed(5)),
                ]),
            };
        }
        const scale = pageScale();
        return {
            space: 'page',
            points: points.map(([x, y]) => [
                Number((x / scale).toFixed(5)),
                Number((y / scale).toFixed(5)),
            ]),
        };
    };
    // Back to document pixels for painting. Null when an anchor-space stroke has
    // no box to measure against, which is how a drawing on a vanished element
    // disappears with it instead of landing somewhere arbitrary.
    const readStroke = (stroke, box) => {
        const points = Array.isArray(stroke && stroke.points)
            ? stroke.points
            : [];
        if (points.length < 2) return null;
        if (stroke.space === 'anchor') {
            if (!box) return null;
            return points.map(([x, y]) => [
                box.left + x * box.width,
                box.top + y * box.height,
            ]);
        }
        const scale = pageScale();
        return points.map(([x, y]) => [x * scale, y * scale]);
    };

    const STROKE_RING_WIDTH = 6;
    const STROKE_WIDTH = 3;
    const paintStroke = (ctx, points) => {
        const sx = window.scrollX;
        const sy = window.scrollY;
        ctx.beginPath();
        points.forEach(([x, y], index) => {
            if (index === 0) ctx.moveTo(x - sx, y - sy);
            else ctx.lineTo(x - sx, y - sy);
        });
        // Same near-black edge every marker the widget paints carries, so a stroke
        // stays legible over a host page of any colour.
        ctx.strokeStyle = CHROME['--pin-ring'];
        ctx.lineWidth = STROKE_RING_WIDTH;
        ctx.stroke();
        ctx.strokeStyle = CHROME['--accent'];
        ctx.lineWidth = STROKE_WIDTH;
        ctx.stroke();
    };

    const renderStrokes = () => {
        const dpr = window.devicePixelRatio || 1;
        const width = window.innerWidth;
        const height = window.innerHeight;
        const backingWidth = Math.round(width * dpr);
        const backingHeight = Math.round(height * dpr);
        if (
            canvasNode.width !== backingWidth ||
            canvasNode.height !== backingHeight
        ) {
            canvasNode.width = backingWidth;
            canvasNode.height = backingHeight;
            canvasNode.style.width = width + 'px';
            canvasNode.style.height = height + 'px';
        }
        const ctx = canvasNode.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        comments.forEach((comment) => {
            // Same page gate the pins use: a drawing made elsewhere is not about this
            // page, whatever space it was stored in.
            if (comment.url !== location.href) return;
            const box = strokeBoxOf(
                anchorsOf(comment).filter((anchor) => anchor.selector),
            );
            strokesOf(comment).forEach((stroke) => {
                const points = readStroke(stroke, box);
                if (points) paintStroke(ctx, points);
            });
        });
        if (!composing()) return;
        state.strokes.forEach((points) => paintStroke(ctx, points));
        if (liveStroke && liveStroke.length > 1) paintStroke(ctx, liveStroke);
    };

    // ---- overlay render (scrim / highlight / pins / toast) ----
    const HIGHLIGHT_PADDING = 8; // px of breathing room around the targeted element

    // What the overlay outlines: one framed element (`move` while picking, or `el`),
    // plus `others`, the rest of the same comment's anchors. Every anchor of the
    // comment being composed stays outlined, and hovering one anchor of a saved
    // comment lights its siblings.
    const highlightTargets = () => {
        const composed = composing()
            ? composeAnchors()
                  .map((entry, anchorIndex) => ({
                      el: entry.el,
                      range: entry.range,
                      label: entry.label,
                      anchorIndex,
                  }))
                  .filter((entry) => entry.el)
            : [];
        // The box carries a remove control only while a new comment is composed and
        // both the picker and the canvas are off. An edit PATCHes the body alone
        // and cannot change anchors, a live picker needs the boxes out of the way,
        // and a removable box would swallow the drag that starts a stroke.
        const removable =
            state.editId == null && !state.target && !state.drawing;
        const mark = (entry) => ({
            el: entry.el,
            range: entry.range,
            anchorIndex: entry.anchorIndex,
            removeIndex: removable ? entry.anchorIndex : null,
        });
        if (state.target) {
            return { move: state.moveHL, others: composed.map(mark) };
        }
        if (composed.length) {
            const last = composed[composed.length - 1];
            return {
                el: last.el,
                range: last.range,
                label: last.label,
                anchorIndex: last.anchorIndex,
                removeIndex: mark(last).removeIndex,
                others: composed.slice(0, -1).map(mark),
            };
        }
        if (state.hoverPinId != null) {
            // The pin key is `${commentIndex}:${anchorIndex}` — frame that one anchor.
            const [commentIndex, anchorIndex] = String(state.hoverPinId)
                .split(':')
                .map(Number);
            const found = resolveAnchors(comments[commentIndex] || {}).filter(
                (entry) => entry.el,
            );
            const framed = found.find(
                (entry) => entry.anchorIndex === anchorIndex,
            );
            return {
                el: framed?.el,
                range: framed?.range,
                others: found
                    .filter((entry) => entry.anchorIndex !== anchorIndex)
                    .map((entry) => ({ el: entry.el, range: entry.range })),
            };
        }
        if (state.hoverId != null && comments[state.hoverId]) {
            const found = anchorHealth(comments[state.hoverId]).found.map(
                (entry) => ({
                    el: entry.el,
                    range: entry.range,
                }),
            );
            return {
                el: found[0] && found[0].el,
                range: found[0] && found[0].range,
                others: found.slice(1),
            };
        }
        return { others: [] };
    };

    // The sibling boxes are pooled and hidden rather than removed, so a hover that
    // moves between anchors repositions nodes instead of recreating them.
    const sibNodes = [];
    const drawSiblings = (entries) => {
        entries.forEach((entry, index) => {
            let node = sibNodes[index];
            if (!node) {
                node = document.createElement('div');
                node.className = 'highlight sib';
                hlsNode.appendChild(node);
                sibNodes[index] = node;
            }
            const r = rectOf(boxOf(entry));
            if (!r) {
                node.style.display = 'none';
                node.classList.remove('removable', 'lit');
                node.dataset.anchorHover = '';
                return;
            }
            node.dataset.anchorKind = anchorKind(entry);
            node.classList.toggle('removable', entry.removeIndex != null);
            node.classList.toggle(
                'lit',
                entry.anchorIndex != null &&
                    state.hoverAnchor === entry.anchorIndex,
            );
            node.dataset.anchorHover =
                entry.anchorIndex == null ? '' : String(entry.anchorIndex);
            node.style.display = 'block';
            node.style.left = r.left - HIGHLIGHT_PADDING + 'px';
            node.style.top = r.top - HIGHLIGHT_PADDING + 'px';
            node.style.width = r.width + HIGHLIGHT_PADDING * 2 + 'px';
            node.style.height = r.height + HIGHLIGHT_PADDING * 2 + 'px';
        });
        for (let index = entries.length; index < sibNodes.length; index++) {
            sibNodes[index].style.display = 'none';
            sibNodes[index].classList.remove('removable', 'lit');
            sibNodes[index].dataset.anchorHover = '';
        }
    };

    // One remove control per removable anchor, pooled and pinned to the padded
    // box's top-right corner. `lit` follows the box the pointer is over.
    const xNodes = [];
    const X_SIZE = 24; // matches .lp-hl-x
    const drawRemoveControls = (entries) => {
        entries.forEach((entry, index) => {
            let node = xNodes[index];
            if (!node) {
                node = document.createElement('button');
                node.className = 'lp-hl-x';
                node.type = 'button';
                node.setAttribute('aria-label', 'Remove this element');
                node.textContent = '×';
                hlxNode.appendChild(node);
                xNodes[index] = node;
            }
            const r = entry.rect;
            // A control for an anchor that is scrolled out of sight has nothing to
            // point at. Clamping it would park an invisible hit target on the edge,
            // where it would eat host-page clicks and remove an anchor nobody can see.
            // Measured on the element, not on its outline, which reaches 8px further.
            const e = entry.inner;
            const onScreen =
                e.left < window.innerWidth &&
                e.left + e.width > 0 &&
                e.top < window.innerHeight &&
                e.top + e.height > 0;
            if (!onScreen) {
                node.style.display = 'none';
                node.removeAttribute('data-anchor-remove');
                return;
            }
            node.style.display = 'flex';
            node.dataset.anchorRemove = String(entry.removeIndex);
            node.classList.toggle(
                'lit',
                state.hoverAnchor === entry.removeIndex,
            );
            // Clamped: an anchor against the top or right edge would otherwise put
            // most of the control off screen, where it cannot be clicked.
            const clamp = (value, limit) =>
                Math.min(Math.max(value, 0), limit - X_SIZE);
            node.style.left =
                clamp(r.left + r.width - X_SIZE / 2, window.innerWidth) + 'px';
            node.style.top =
                clamp(r.top - X_SIZE / 2, window.innerHeight) + 'px';
        });
        for (let index = entries.length; index < xNodes.length; index++) {
            xNodes[index].style.display = 'none';
            xNodes[index].removeAttribute('data-anchor-remove');
        }
    };

    const outset = (r) => ({
        left: r.left - HIGHLIGHT_PADDING,
        top: r.top - HIGHLIGHT_PADDING,
        width: r.width + HIGHLIGHT_PADDING * 2,
        height: r.height + HIGHLIGHT_PADDING * 2,
    });

    const updateHighlight = () => {
        const targets = highlightTargets();
        const others = targets.others || [];
        drawSiblings(others);
        const framedRemove = targets.move ? null : targets.removeIndex;
        const framedAnchor = targets.move ? null : targets.anchorIndex;
        hlNode.classList.toggle('removable', framedRemove != null);
        hlNode.classList.toggle(
            'lit',
            framedAnchor != null && state.hoverAnchor === framedAnchor,
        );
        hlNode.dataset.anchorHover =
            framedAnchor == null ? '' : String(framedAnchor);
        let hl = null;
        if (targets.move) {
            hl = targets.move;
            hlNode.dataset.anchorKind = 'element';
        } else if (targets.el) {
            const r = rectOf(boxOf(targets));
            if (r) {
                hl = {
                    left: r.left,
                    top: r.top,
                    width: r.width,
                    height: r.height,
                    label: targets.label || null,
                };
                hlNode.dataset.anchorKind = anchorKind(targets);
            }
        }
        // Every removable anchor gets a control, the framed one included, and each is
        // measured from the same outset rect the box is drawn with.
        const controls = [];
        others.forEach((entry) => {
            const r = entry.removeIndex != null && rectOf(boxOf(entry));
            if (r)
                controls.push({
                    rect: outset(r),
                    inner: r,
                    removeIndex: entry.removeIndex,
                });
        });
        if (hl && framedRemove != null) {
            controls.push({
                rect: outset(hl),
                inner: hl,
                removeIndex: framedRemove,
            });
        }
        drawRemoveControls(controls);
        if (!hl) {
            hlNode.style.display = 'none';
            hlNode.classList.remove('removable', 'lit');
            hlNode.dataset.anchorHover = '';
            hlNode.dataset.anchorKind = '';
            return;
        }
        // Outset the measured rect so the outline frames the element rather than
        // tracing its edge. Applied before the label is placed, so the label keeps
        // sitting against the box the reviewer actually sees.
        hl = { ...hl, ...outset(hl) };
        hlNode.style.display = 'block';
        hlNode.style.left = hl.left + 'px';
        hlNode.style.top = hl.top + 'px';
        hlNode.style.width = hl.width + 'px';
        hlNode.style.height = hl.height + 'px';
        if (hl.label) {
            // Block, not inline-flex: text-overflow has no effect on a flex container's
            // anonymous text item, so the label would hard-clip mid-word instead of
            // ellipsing when the element's first line runs past the pill's max width.
            hlLabel.style.display = 'block';
            hlLabel.textContent = hl.label;
            // The label normally sits just above the box's left corner; when the box hugs an
            // edge of the viewport that placement clips off-screen, so flip to the other side.
            // Vertical: drop below the box when there's no room above.
            hlLabel.style.top = hl.top >= 26 ? '-25px' : hl.height + 4 + 'px';
            // Horizontal: anchor to the box's right edge (extending left) when a left-anchored
            // label would run off the right of the viewport.
            const overflowRight =
                hl.left - 2 + hlLabel.offsetWidth > window.innerWidth;
            hlLabel.style.left = overflowRight ? 'auto' : '-2px';
            hlLabel.style.right = overflowRight ? '-2px' : 'auto';
        } else {
            hlLabel.style.display = 'none';
        }
    };

    // Slide a confirm overlay back out to the left, then remove it. Marked exiting so
    // repeated renders don't restart the animation or double-remove. The timer removes
    // it when no animationend comes, as a busy or hidden page can skip the event.
    const slideOut = (el) => {
        if (!el || el.dataset.exiting) return;
        el.dataset.exiting = '1';
        el.style.animation =
            'lp-slide-left-out .18s cubic-bezier(.4,0,.2,1) forwards';
        const remove = () => el.remove();
        el.addEventListener('animationend', remove, { once: true });
        el.addEventListener('animationcancel', remove, { once: true });
        setTimeout(remove, 300);
    };

    // Pin nodes are reconciled (not rebuilt) so the lp-pin drop-in animation plays
    // once per pin; hovering and scrolling reposition existing nodes rather than
    // recreating them (recreating would replay the scale-in and make the pin shrink).
    const pinNodes = new Map();
    // The card (body + chip + delete) is built once on hover; the confirm overlay is
    // a separate node toggled in place, so arming/cancelling delete never rebuilds the
    // card (which would replay its fade-in and flicker).
    const buildPopover = (comment, index, info) => {
        const label = anchorLabel(info.anchor, info.kind);
        // A degraded comment names how many of its elements survived, so the reviewer
        // is not left reading a note about two things beside a pin on one.
        const missing = info.total - info.foundCount;
        const warning = info.degraded
            ? `<div class="lp-pop-degraded">${ICON.alertCircle(14)}<span>${escapeHtml(
                  `${missing} of ${info.total} elements ${missing === 1 ? 'is' : 'are'} no longer on this page.`,
              )}</span></div>`
            : '';
        const chipText =
            info.total > 1 ? `${info.total} elements` : label || '';
        const chip = chipText
            ? `<div class="lp-pop-meta"><span class="lp-pop-chip">${escapeHtml(chipText)}</span></div>`
            : '';
        return `<div class="lp-pop">
        <div class="lp-pop-card">
          <div class="lp-pop-main">
            <div class="lp-pop-body">${escapeHtml(comment.body)}</div>
            ${chip}
          </div>
          ${warning}
          <div class="lp-pop-foot">
            <button class="lp-pop-edit" data-pin-edit="${index}">Edit</button>
            <div style="flex:1"></div>
            <button class="lp-pop-more" data-pin-more="${index}" aria-label="More actions" aria-haspopup="menu" aria-expanded="false">${ICON.more(16)}</button>
          </div>
          <div class="lp-pop-menu" role="menu" hidden>
            <button class="lp-pop-menu-item" role="menuitem" data-pin-resolve="${index}">Mark resolved</button>
            <div class="lp-pop-menu-sep" role="separator"></div>
            <button class="lp-pop-menu-item danger" role="menuitem" data-pin-del="${index}">Delete note…</button>
          </div>
        </div>
      </div>`;
    };
    // The popover's resting place is up and to the left of its pin, which puts it
    // off-screen for a pin near the left or bottom edge of the viewport. Nudge it
    // back inside. This runs on every reposition pass, not only when the card is
    // built, because the card survives scrolling — clamping once would let it
    // drift back out as the page moves under it.
    const POPOVER_MARGIN = 8;
    const PIN_SIZE = 26; // .pin width in the overlay stylesheet
    const POPOVER_OFFSET = 18; // .lp-pop top offset in the overlay stylesheet
    const clampPopover = (popover, info) => {
        if (!popover) return;
        // The wrap is the pin button, so the popover's default box in viewport
        // coordinates starts at the wrap's right edge minus its own width.
        const width = popover.offsetWidth;
        const height = popover.offsetHeight;
        const defaultLeft = info.left + PIN_SIZE - width;
        const defaultTop = info.top + POPOVER_OFFSET;
        const maxLeft = Math.max(
            POPOVER_MARGIN,
            window.innerWidth - width - POPOVER_MARGIN,
        );
        const maxTop = Math.max(
            POPOVER_MARGIN,
            window.innerHeight - height - POPOVER_MARGIN,
        );
        const left = Math.min(Math.max(defaultLeft, POPOVER_MARGIN), maxLeft);
        const top = Math.min(Math.max(defaultTop, POPOVER_MARGIN), maxTop);
        // Positions are relative to the wrap, so convert back from viewport space.
        popover.style.right = 'auto';
        popover.style.left = `${left - info.left}px`;
        popover.style.top = `${top - info.top}px`;
    };

    const buildConfirm = (index) =>
        `<div class="lp-pop-confirm">
       <div class="lp-pop-confirm-title">Delete this note?</div>
       <div class="lp-pop-confirm-row">
         <button class="lp-pop-yes" data-pin-yes="${index}">Delete</button>
         <button class="lp-pop-no" data-pin-no="${index}">Cancel</button>
       </div>
     </div>`;
    const bindPopover = (holder, key, commentIndex) => {
        const more = holder.querySelector('[data-pin-more]');
        const menu = holder.querySelector('.lp-pop-menu');
        more.addEventListener('click', () => {
            menu.hidden = !menu.hidden;
            more.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        });
        holder.querySelector('[data-pin-del]').addEventListener('click', () => {
            menu.hidden = true;
            more.setAttribute('aria-expanded', 'false');
            state.pinConfirmId = key;
            renderPins();
        });
        holder
            .querySelector('[data-pin-edit]')
            .addEventListener('click', () => openEditComposer(commentIndex));
        // No confirm step, unlike delete: resolving keeps the comment and the
        // owner can reopen it from its card's Feedback tab.
        holder
            .querySelector('[data-pin-resolve]')
            .addEventListener('click', () => resolveComment(commentIndex));
    };
    const bindConfirm = (el, index) => {
        const yes = el.querySelector('[data-pin-yes]');
        if (yes) yes.addEventListener('click', () => removeComment(index));
        const no = el.querySelector('[data-pin-no]');
        if (no)
            no.addEventListener('click', () => {
                state.pinConfirmId = null;
                renderPins();
            });
    };
    const renderPins = () => {
        ovRoot.classList.toggle('targeting', state.target);
        ovRoot.classList.toggle('drawing', state.drawing);
        // One pin per resolved anchor, keyed by comment and anchor so a comment on
        // several elements gets a pin on each. Every pin of one comment carries that
        // comment's number, which is what shows they belong together.
        const wanted = new Map();
        const ranked = displayOrder().all;
        comments.forEach((comment, index) => {
            const { found, total, degraded } = anchorHealth(comment);
            found.forEach((entry) => {
                const r = rectOf(boxOf(entry));
                if (!r) return;
                const onScreen = !(
                    r.bottom < 0 ||
                    r.top > window.innerHeight ||
                    r.right < 0 ||
                    r.left > window.innerWidth
                );
                wanted.set(`${index}:${entry.anchorIndex}`, {
                    comment,
                    index,
                    rank: ranked.indexOf(index),
                    anchor: entry.anchor,
                    kind: anchorKind(entry),
                    total,
                    foundCount: found.length,
                    degraded,
                    left: r.left + r.width - 16,
                    top: r.top - 8,
                    onScreen,
                });
            });
        });
        // Drop pins whose element is gone (deleted, or anchored to another page).
        pinNodes.forEach((wrap, key) => {
            if (!wanted.has(key)) {
                wrap.remove();
                pinNodes.delete(key);
            }
        });
        wanted.forEach((info, key) => {
            let wrap = pinNodes.get(key);
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.className = 'lp-pin-wrap';
                wrap.innerHTML =
                    `<button class="pin"></button>` +
                    `<div class="lp-pop-holder"></div>`;
                wrap.querySelector('.pin').addEventListener('click', () => {
                    state.open = true;
                    sync();
                });
                wrap.addEventListener('mouseenter', () => hoverPin(key));
                wrap.addEventListener('mouseleave', unhoverPin);
                pinsNode.appendChild(wrap);
                pinNodes.set(key, wrap);
            }
            wrap.style.left = info.left + 'px';
            wrap.style.top = info.top + 'px';
            wrap.style.display = info.onScreen ? '' : 'none';
            const pin = wrap.querySelector('.pin');
            pin.textContent = String(info.rank + 1);
            pin.dataset.anchorKind = info.kind;
            pin.classList.toggle('degraded', info.degraded);
            pin.classList.toggle('act', state.hoverPinId === key);
            // Build the card once on hover; toggle the confirm overlay in place. Neither
            // is rebuilt on scroll, so nothing re-animates while repositioning.
            const hovered = state.hoverPinId === key;
            const confirming = hovered && state.pinConfirmId === key;
            const holder = wrap.querySelector('.lp-pop-holder');
            if (!hovered) {
                if (holder.dataset.shown) {
                    holder.innerHTML = '';
                    delete holder.dataset.shown;
                }
            } else {
                if (!holder.dataset.shown) {
                    holder.innerHTML = buildPopover(
                        info.comment,
                        info.index,
                        info,
                    );
                    holder.dataset.shown = '1';
                    bindPopover(holder, key, info.index);
                }
                clampPopover(holder.querySelector('.lp-pop'), info);
                const card = holder.querySelector('.lp-pop-card');
                const liveConfirm = card.querySelector(
                    '.lp-pop-confirm:not([data-exiting])',
                );
                if (confirming && !liveConfirm) {
                    card.querySelectorAll('.lp-pop-confirm').forEach((node) =>
                        node.remove(),
                    );
                    card.insertAdjacentHTML(
                        'beforeend',
                        buildConfirm(info.index),
                    );
                    bindConfirm(
                        card.querySelector('.lp-pop-confirm'),
                        info.index,
                    );
                } else if (!confirming && liveConfirm) {
                    slideOut(liveConfirm);
                }
            }
        });
    };

    // Both toasts rest against the edge opposite the launcher, so a launcher moved
    // to a top corner never sits under one. The pick-mode toast can still cover the
    // element the reviewer wants to click, so it auto-dodges: the cursor entering
    // the band at its own edge sends it to the other one, and it slides back after.
    const TOAST_DODGE_BAND = 140; // px from the toast's resting edge
    const toastHome = () => (state.corner.startsWith('top') ? 'bottom' : 'top');
    const dockToast = (node, dock) => {
        // At the bottom it keeps the gap it has from the top edge.
        const gap = parseFloat(getComputedStyle(node).top) || 0;
        const offset =
            dock === 'bottom'
                ? window.innerHeight - node.offsetHeight - 2 * gap
                : 0;
        node.style.setProperty('--lp-dock', `${offset}px`);
        node.style.setProperty(
            '--lp-hide',
            dock === 'bottom' ? '24px' : '-24px',
        );
    };
    // A toast slides in from its edge and back out. Its resting place is set
    // with transitions off while it is hidden, so it never crosses the screen.
    const setToast = (node, on, dock) => {
        if (on) {
            const quiet = !node.classList.contains('on');
            if (quiet) node.style.transition = 'none';
            dockToast(node, dock);
            if (quiet) {
                void node.offsetHeight;
                node.style.transition = '';
            }
        }
        node.classList.toggle('on', on);
    };
    const positionToast = () => dockToast(toastNode, state.toastDock);
    const updateToastDodge = (clientY) => {
        const home = toastHome();
        const away = home === 'top' ? 'bottom' : 'top';
        const nearHome =
            clientY != null &&
            (home === 'top'
                ? clientY < TOAST_DODGE_BAND
                : clientY > window.innerHeight - TOAST_DODGE_BAND);
        const dock = nearHome ? away : home;
        if (state.toastDock === dock) return;
        state.toastDock = dock;
        positionToast();
    };

    // The offer sits just under the selection, flipping above it near the bottom
    // edge and clamped horizontally, on the same reasoning as the pin popover.
    const QUOTE_BTN_GAP = 8;
    const QUOTE_BTN_HEIGHT = 40; // matches .lp-quote-btn
    const renderQuoteButton = () => {
        const pick = state.quotePick;
        // Hidden while another mode owns the pointer, drawing included: the canvas
        // sits under this button, so an offer left up would eat part of a stroke
        // and could add a quote without leaving draw mode. The selection survives,
        // so the offer comes back when the mode ends.
        if (
            !pick ||
            state.target ||
            state.drawing ||
            state.fatal ||
            !state.open ||
            state.editId != null ||
            hidden.matches
        ) {
            quoteBtn.style.display = 'none';
            return;
        }
        quoteBtn.style.display = 'inline-flex';
        const r = pick.range.getBoundingClientRect();
        const maxLeft = Math.max(
            QUOTE_BTN_GAP,
            window.innerWidth - quoteBtn.offsetWidth - QUOTE_BTN_GAP,
        );
        const below = r.bottom + QUOTE_BTN_GAP;
        const fits =
            below + QUOTE_BTN_HEIGHT + QUOTE_BTN_GAP <= window.innerHeight;
        quoteBtn.style.left =
            Math.min(Math.max(r.left, QUOTE_BTN_GAP), maxLeft) + 'px';
        quoteBtn.style.top =
            (fits
                ? below
                : Math.max(
                      QUOTE_BTN_GAP,
                      r.top - QUOTE_BTN_HEIGHT - QUOTE_BTN_GAP,
                  )) + 'px';
    };

    const clearQuotePick = () => {
        if (!state.quotePick) return;
        state.quotePick = null;
        renderQuoteButton();
    };

    const renderOverlay = () => {
        scrimNode.style.display = state.target ? 'block' : 'none';
        toastText.textContent = state.addAnchor
            ? 'Click to add another element'
            : 'Click to add a note';
        setToast(toastNode, state.target, state.toastDock);
        // Every toast shares one resting edge, so the mode the reviewer is in wins.
        // The saved notice clears itself on a timer, and drawing again inside that
        // window would otherwise bury the stroke count and its Undo and Clear.
        const showSaved =
            !!state.savedNotice && !state.target && !state.drawing;
        if (showSaved) $$('lp-saved-text').textContent = state.savedNotice;
        setToast(savedToastNode, showSaved, toastHome());
        setToast(drawToastNode, state.drawing, toastHome());
        if (state.drawing) {
            // Parked at the edge away from the launcher, and never dodging: the
            // pointer is busy drawing, and a toast that moved would carry its own
            // Undo and Clear out from under the reviewer.
            const count = state.strokes.length;
            $$('lp-draw-text').textContent =
                count === 0
                    ? 'Drag to draw'
                    : count === 1
                      ? '1 stroke'
                      : `${count} strokes`;
            $$('lp-draw-undo').disabled = count === 0;
            $$('lp-draw-clear').disabled = count === 0;
        }
        renderPins();
        updateHighlight();
        renderStrokes();
        renderQuoteButton();
    };

    // Copy for a failed mutation. Auth failures (401/403) never reach here — authFailed()
    // promotes them to the fatal state. A 404 is its own case: the comment stopped being
    // editable because the agent picked it up, so retrying would fail the same way.
    const errorText = (error) => {
        if (error && error.code === 'delivery_conflict') {
            return 'The earlier submission is saved with different content. Copy your draft, then reload to review it.';
        }
        if (error && error.code === 'widget_outdated') {
            return 'Reload the page to update the review widget.';
        }
        if (error && /^target_/.test(error.code || '')) {
            return 'That card is closed or gone. Choose where your notes go, then save again.';
        }
        if (error && error.status === 404) {
            return 'Your agent has already picked that note up, so it can’t be changed now.';
        }
        // A widget-side refusal carries its own wording and no HTTP status.
        if (error && !error.status && error.message) return error.message;
        return 'Couldn’t apply that change. Please try again.';
    };

    // Detail line for the fatal panel, keyed on the server's error code with a
    // status fallback. Each case has its own fix, so one generic wording would
    // send the reader down the wrong path.
    const fatalDetail = ({ status, code }) => {
        if (
            'credential_not_allowed' === code ||
            'insufficient_scope' === code
        ) {
            return 'This sign-in does not cover live review. Sign in again, and allow the live-review access the page asks for.';
        }
        if (403 === status) {
            return 'This account cannot review this project. Sign in as a person the project allows.';
        }
        if (
            401 === status ||
            'invalid_credentials' === code ||
            'unauthorized' === code
        ) {
            return 'This sign-in expired or was revoked. Sign in again to carry on.';
        }
        return 'The server refused this sign-in. Check that the embed names the right project, and that the project allows this page’s address.';
    };

    // ---- panel render ----
    // A note is "here" when it was made on the page the reviewer is on. The
    // list shows those first, and pins number the notes in the same order.
    const isHere = (comment) => !comment.url || comment.url === location.href;
    const displayOrder = () => {
        const here = [];
        const other = [];
        comments.forEach((comment, index) =>
            (isHere(comment) ? here : other).push(index),
        );

        return { here, other, all: [...here, ...other] };
    };

    const VERDICT_WORDS = {
        approve: 'Approved',
        'request-changes': 'Changes requested',
        comment: 'Commented',
    };
    const pullRequestName = (prs) => {
        const number =
            prs.length === 1 ? /#(\d+)\s*$/.exec(prs[0].label) : null;

        return prs.length > 1
            ? `${prs.length} pull requests`
            : number
              ? `PR #${number[1]}`
              : 'Pull request';
    };
    // The second header line of a locked card: its pull request and the last
    // verdict, or what the card holds while it has no pull request.
    const cardLine = () => {
        const data = verdictCurrent() ? verdict.data : null;
        if (!data) return '';
        const prs = data.pullRequests;
        if (!prs.length) {
            const last = data.latestVerdict
                ? ` · Last verdict: ${VERDICT_WORDS[data.latestVerdict.kind] || data.latestVerdict.kind}`
                : '';

            return `No pull request yet${last}`;
        }
        const name = pullRequestName(prs);
        const last = data.latestVerdict
            ? VERDICT_WORDS[data.latestVerdict.kind] || data.latestVerdict.kind
            : 'No verdict yet';

        return `${name} · ${last}`;
    };
    // The footer's one action: finish a pull request review, or open the one
    // card that the notes go to.
    const openAction = (card) => {
        if (!card.url) return null;
        const number = /^#(\d+)/.exec(card.label || '');

        return {
            kind: 'open',
            text: number ? `Open #${number[1]} in Loupe` : 'Open card in Loupe',
            url: card.url,
        };
    };
    const deleteAllCardTitle = () => {
        if (lock) return lock.state === 'ok' ? lock.label || '' : '';
        const target = storedTarget;

        return target && target.mode === 'per-review' ? target.label || '' : '';
    };
    const footerAction = () => {
        if (!lock) {
            return storedTarget && storedTarget.mode === 'per-review'
                ? openAction(storedTarget)
                : null;
        }
        if (lock.state !== 'ok') return null;
        const data = verdictCurrent() ? verdict.data : null;
        if (data && data.pullRequests.length) return { kind: 'finish' };

        return openAction(lock);
    };

    // Pick the view to show from what is true now. A view whose reason is gone
    // closes, so a stale flag can never leave an empty view on screen.
    const normalizeView = () => {
        if (signedOut()) {
            if (state.view !== 'signin') resetView('signin');
            return;
        }
        if (state.view === 'signin') resetView();
        if (state.view === 'compose' && !composing()) resetView();
        if (state.view === 'destination' && lock) {
            if (composing()) showView('compose');
            else resetView();
        }
        if (state.view === 'finish' && !verdictCurrent()) resetView();
    };
    const focusFirstControl = (node) =>
        [
            ...node.querySelectorAll(
                'button:not([disabled]), a[href], input, textarea, select',
            ),
        ].some((control) => {
            control.focus({ preventScroll: true });

            return root.activeElement === control;
        });
    const applyViewClasses = () => {
        viewsNode.dataset.view = state.view;
        const focused = root.activeElement;
        const stranded =
            !!focused &&
            Object.entries(viewNodes).some(
                ([name, node]) => name !== state.view && node.contains(focused),
            );
        Object.entries(viewNodes).forEach(([name, node]) => {
            const current = name === state.view;
            node.classList.toggle(
                'is-under',
                !current && (name === 'list' || state.stack.includes(name)),
            );
            node.classList.toggle(
                'is-away',
                !current && name !== 'list' && !state.stack.includes(name),
            );
            // Inert at once, so Tab and the accessibility tree skip a view
            // that is sliding out, and the current view takes focus calls
            // the moment it becomes current.
            node.toggleAttribute('inert', !current);
            node.setAttribute('aria-hidden', current ? 'false' : 'true');
        });
        // Focus left in a view that turned inert lands on the first control
        // of the current view.
        if (stranded) focusFirstControl(viewNodes[state.view]);
    };
    // The panel is as tall as its current view, up to the viewport budget.
    const fitPanel = (instant) => {
        const height = viewNodes[state.view].offsetHeight;
        if (!height) return;
        if (instant) panelNode.style.transition = 'none';
        panelNode.style.height = `${height + 2}px`;
        if (instant) {
            void panelNode.offsetHeight;
            panelNode.style.transition = '';
        }
    };
    if (typeof ResizeObserver === 'function') {
        const watcher = new ResizeObserver(() => fitPanel(false));
        Object.values(viewNodes).forEach((node) => watcher.observe(node));
    }

    // ---- menus: one floating menu, owned by the button that opened it ----
    let menu = null; // { button, kind: 'row' | 'head', id, count }
    const MENU_GAP = 4;
    const closeMenu = (restoreFocus) => {
        if (!menu) return;
        const { button } = menu;
        menu = null;
        menuNode.style.display = 'none';
        menuNode.style.width = '';
        menuNode.textContent = '';
        button.setAttribute('aria-expanded', 'false');
        if (restoreFocus) button.focus({ preventScroll: true });
    };
    const menuItem = (label, action, options = {}) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'lp-menu-item' + (options.danger ? ' danger' : '');
        item.setAttribute('role', 'menuitem');
        item.dataset.menuAction = options.name;
        item.textContent = label;
        item.disabled = !!options.disabled;
        item.addEventListener('click', action);

        return item;
    };
    const menuSeparator = () => {
        const separator = document.createElement('div');
        separator.className = 'lp-menu-sep';
        separator.setAttribute('role', 'separator');

        return separator;
    };
    // The menu swaps its items for a question and two buttons, so a delete is
    // confirmed where it was asked for. Delete takes focus, as the first
    // button of the menu, and Escape or Cancel backs out.
    const menuConfirm = (text, onYes, ids = {}) => {
        const question = document.createElement('div');
        question.className = 'lp-menu-conf';
        question.textContent = text;
        if (ids.text) question.id = ids.text;
        const row = document.createElement('div');
        row.className = 'lp-menu-conf-row';
        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'lp-clear-yes';
        yes.textContent = 'Delete';
        if (ids.yes) yes.id = ids.yes;
        yes.addEventListener('click', () => {
            closeMenu(false);
            onYes();
        });
        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'lp-clear-cancel';
        no.textContent = 'Cancel';
        if (ids.no) no.id = ids.no;
        no.addEventListener('click', () => closeMenu(true));
        row.append(yes, no);
        menu.confirming = true;
        menuNode.style.width = '200px';
        menuNode.replaceChildren(question, row);
        placeMenu();
        yes.focus({ preventScroll: true });
    };
    // Below the button when it fits, above when it does not and there is more
    // room up. A row near the foot of the list opens upward.
    const placeMenu = () => {
        const rect = menu.button.getBoundingClientRect();
        const height = menuNode.offsetHeight;
        const width = menuNode.offsetWidth;
        const limit =
            menu.kind === 'row'
                ? Math.min(
                      window.innerHeight,
                      $('lp-body').getBoundingClientRect().bottom,
                  )
                : window.innerHeight;
        const below = limit - rect.bottom;
        const up = below < height + MENU_GAP && rect.top > below;
        // A row menu lines up with its row's inner edge and a header menu with
        // the close button's, and a header menu hangs 2px under its button.
        const edge =
            menu.kind === 'head'
                ? $('lp-close').getBoundingClientRect().right
                : menu.button.closest('.lp-item').getBoundingClientRect()
                      .right - 10;
        const gap = menu.kind === 'head' ? 2 : MENU_GAP;
        const left = Math.min(
            Math.max(edge - width, MENU_GAP),
            Math.max(MENU_GAP, window.innerWidth - width - MENU_GAP),
        );
        menuNode.classList.toggle('up', up);
        menuNode.style.left = `${left}px`;
        menuNode.style.top = `${
            up ? rect.top - height - MENU_GAP : rect.bottom + gap
        }px`;
    };
    const openMenu = (button, kind, id, items) => {
        const toggledOff = !!menu && menu.button === button;
        closeMenu(false);
        if (toggledOff) return;
        menu = { button, kind, id, count: comments.length };
        menuNode.replaceChildren(...items);
        menuNode.style.display = 'flex';
        placeMenu();
        button.setAttribute('aria-expanded', 'true');
        const first = items.find((node) =>
            node.matches('button:not([disabled])'),
        );
        if (first) first.focus({ preventScroll: true });
    };
    const openRowMenu = (button, index) => {
        const comment = comments[index];
        if (!comment) return;
        const at = () => comments.findIndex((row) => row.id === comment.id);
        const act = (run) => () => {
            const found = at();
            closeMenu(false);
            if (found !== -1) run(found);
        };
        // A per-note review files each note as its own card, so the row is
        // the only place that names it.
        const link =
            !lock &&
            storedTarget &&
            storedTarget.mode === 'per-note' &&
            comment.link &&
            /^https?:\/\//.test(comment.link.url || '')
                ? comment.link
                : null;
        openMenu(button, 'row', comment.id, [
            ...(link
                ? [
                      menuItem(
                          `Open ${link.label} in Loupe`,
                          () => {
                              closeMenu(false);
                              window.open(link.url, '_blank', 'noopener');
                          },
                          { name: 'open' },
                      ),
                  ]
                : []),
            menuItem('Edit text', act(openEditComposer), { name: 'edit' }),
            menuItem('Mark resolved', act(resolveComment), {
                name: 'resolve',
            }),
            menuSeparator(),
            menuItem(
                'Delete note…',
                () => {
                    if (at() === -1) {
                        closeMenu(true);
                        return;
                    }
                    menuConfirm('Delete this note?', () => {
                        const found = at();
                        if (found !== -1) removeComment(found);
                    });
                },
                { name: 'delete', danger: true },
            ),
        ]);
    };
    const openHeadMenu = () => {
        const items = [
            menuItem(
                `Move launcher to the ${CORNER_LABEL[nextCorner()]}`,
                () => {
                    closeMenu(true);
                    setCorner(nextCorner());
                },
                { name: 'move' },
            ),
        ];
        if (OAUTH && grant) {
            items.push(
                menuItem(
                    'Sign out',
                    () => {
                        closeMenu(false);
                        setGrant(null);
                        showSignedOut();
                    },
                    { name: 'sign-out' },
                ),
            );
        }
        if (comments.length > 0) {
            items.push(
                menuSeparator(),
                menuItem(
                    comments.length === 1
                        ? 'Delete 1 note…'
                        : `Delete all ${comments.length} notes…`,
                    () => {
                        const cardNumber = /^#(\d+)/.exec(deleteAllCardTitle());
                        const count = comments.length;
                        // Only a one-card scope leaves the notes of other cards.
                        const oneCard = scopeOf().startsWith('card:');
                        menuConfirm(
                            `${
                                count === 1
                                    ? 'Delete 1 note'
                                    : `Delete all ${count} notes`
                            }${cardNumber ? ` on #${cardNumber[1]}` : ''}? ${
                                oneCard
                                    ? 'Notes on other cards stay.'
                                    : 'This deletes them on every card they belong to.'
                            }`,
                            confirmClearYes,
                            {
                                text: 'lp-clear-confirm-text',
                                yes: 'lp-clear-yes',
                                no: 'lp-clear-cancel',
                            },
                        );
                    },
                    {
                        name: 'delete-all',
                        danger: true,
                        disabled: state.saving || state.deleting,
                    },
                ),
            );
        }
        openMenu(headMenuBtn, 'head', null, items);
    };
    // A menu closes with the reason it was opened: another view, a closed
    // panel, a note that is gone or a list that changed.
    const normalizeMenu = () => {
        if (!menu) return;
        const rowGone =
            menu.kind === 'row' &&
            !comments.some((comment) => comment.id === menu.id);
        const countMoved =
            menu.kind === 'head' && menu.count !== comments.length;
        if (
            !state.open ||
            state.fatal ||
            state.view !== 'list' ||
            (state.target && !state.addAnchor) ||
            rowGone ||
            countMoved
        ) {
            closeMenu(false);
        }
    };
    menuNode.addEventListener('keydown', (event) => {
        const items = [
            ...menuNode.querySelectorAll('[role="menuitem"]:not([disabled])'),
        ];
        const at = items.indexOf(root.activeElement);
        let next = null;
        if (event.key === 'ArrowDown') next = items[(at + 1) % items.length];
        else if (event.key === 'ArrowUp')
            next = items[(at - 1 + items.length) % items.length];
        else if (event.key === 'Home') next = items[0];
        else if (event.key === 'End') next = items[items.length - 1];
        else if (event.key === 'Tab') {
            if (menu && menu.confirming) return;
            event.preventDefault();
            closeMenu(true);
            return;
        }
        if (!next) return;
        event.preventDefault();
        next.focus({ preventScroll: true });
    });
    headMenuBtn.addEventListener('click', openHeadMenu);
    document.addEventListener(
        'pointerdown',
        (event) => {
            if (!menu) return;
            const path = event.composedPath();
            if (path.includes(menuNode) || path.includes(menu.button)) return;
            closeMenu(false);
        },
        true,
    );
    $('lp-body').addEventListener('scroll', () => closeMenu(false));
    window.addEventListener('resize', () => closeMenu(false));
    // The panel grows from its foot, so its rows move on each frame of a
    // height change, animated or not.
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(() => {
            if (menu) placeMenu();
        }).observe(panelNode);
    }

    const saveDisabled = () =>
        state.saving ||
        !state.draft.trim() ||
        (composing() && state.editId == null && !activeTarget());
    // The card number alone says where a note goes, and the full title is one tap away.
    const cardNumberOf = (label) =>
        ((label || '').match(/^#\d+/) || [label || ''])[0];
    const saveTargetText = (target) => {
        const named = cardNumberOf(target.label);
        if (target.mode === 'per-note') return 'a new card';
        if (target.mode === 'epic') {
            return named
                ? `a new card under ${named}`
                : 'a new card under an epic';
        }
        return named || 'the review card';
    };
    // The line under the title of the note view: where a new note goes, or what
    // an edit may change.
    let contextKey = null;
    const renderContext = (newNote) => {
        const target = composing() ? activeTarget() : null;
        const key = JSON.stringify([
            composing(),
            newNote,
            lock ? lock.state : null,
            lock && lock.label,
            target && [target.mode, target.label],
        ]);
        if (key === contextKey) return;
        contextKey = key;
        contextNode.textContent = '';
        delete contextNode.dataset.role;
        if (!composing()) return;
        if (!newNote) {
            contextNode.textContent = 'Only the text of a note can change.';
            contextNode.dataset.role = 'edit-hint';
            return;
        }
        const say = (text, wrap) => {
            const line = document.createElement('span');
            line.className = 'lp-context-label';
            line.dataset.role = 'target';
            line.textContent = text;
            if (wrap) line.dataset.wrap = '';
            contextNode.appendChild(line);
        };
        if (lock && lock.state === 'broken') {
            say(
                'This preview’s card is closed or gone, so notes cannot be saved here.',
                true,
            );
            return;
        }
        contextNode.append('Saves to ');
        if (lock) {
            say(cardNumberOf(lock.label) || 'this preview’s card');
            return;
        }
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'lp-context-label';
        choose.dataset.role = 'picker';
        if (target) {
            choose.textContent = saveTargetText(target);
            choose.title = `${targetLabel(target)}. Change where notes go.`;
        } else {
            contextNode.textContent = '';
            choose.textContent = 'Choose where your notes go.';
        }
        choose.addEventListener('click', openDestination);
        contextNode.appendChild(choose);
    };
    const updatePanel = () => {
        normalizeView();
        normalizeMenu();
        const fatal = null !== state.fatal;
        const outage = fatal && !signedOut();
        const n = comments.length;
        const launchCount = $('lp-launch-count');
        launchCount.style.display = !fatal && n > 0 ? 'inline-flex' : 'none';
        launchCount.textContent = String(n);
        // A rejected token surfaces on the collapsed launcher too — a danger "!" badge — so
        // the problem is visible before the panel is ever opened.
        $('lp-launch-alert').style.display = outage ? 'inline-flex' : 'none';
        // A signed-out reviewer is told what to do rather than shown an error.
        $('lp-launch-dot').style.display = signedOut() ? '' : 'none';
        $('lp-launch-label').textContent = signedOut()
            ? 'Sign in to review'
            : outage
              ? 'Review can’t connect'
              : 'Review';

        // While picking an element, hide the whole widget (launcher + panel) so it does
        // not obscure the page; the scrim + toast are the only pick-mode UI. Add-anchor
        // mode is the exception: hiding the panel blurs the textarea, which would send
        // the reviewer's next ⌘V somewhere other than their draft, and it would hold the
        // chip list a pick behind the anchors it lists.
        const picking = state.target && !state.addAnchor;
        launcherNode.style.display = picking ? 'none' : '';
        // Absent rather than disabled while the instance offers no drawing, the
        // same as the in-panel control. The collapsed launcher has no room to
        // explain a control that does nothing.
        launchDrawBtn.style.display = drawingEnabled ? '' : 'none';
        // The tool in use takes the accent fill, wherever the launcher shows it.
        const noteActive =
            composing() && (state.composeTarget || {}).type === 'general';
        [
            ['lp-launch-note', noteActive],
            ['lp-launch-target', state.target],
            ['lp-launch-draw', state.drawing],
        ].forEach(([id, on]) => {
            $(id).classList.toggle('on', on);
            $(id).setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        // The launcher's quick actions duplicate the in-panel ones, so hide them (keeping only
        // the Review toggle) whenever the panel is open — or fatal, where they'd only launch a
        // composer the critical state immediately hides.
        launcherNode.classList.toggle('open', state.open || fatal);
        // A host page may float its own chrome beside the launcher. Both the panel
        // and pick mode take the space over, so say when that happens.
        document.documentElement.toggleAttribute(
            'data-loupe-review-open',
            state.open || Boolean(state.target),
        );
        // Clip the quick actions while they collapse, or they slide over Review and take
        // its click. Once expanded and idle, the transitionend handler lets tooltips out.
        if (state.open || fatal) launchQuick.style.overflow = 'hidden';
        const showing = state.open && !picking;
        const opening = showing && panelNode.classList.contains('is-closed');
        // The panel keeps its box while it slides out, so the class and the
        // inert flag follow `showing` and the height is set before it opens.
        const setPanelShown = (shown) => {
            panelNode.classList.toggle('is-closed', !shown);
            panelNode.toggleAttribute('inert', !shown);
        };
        if (!showing) {
            setPanelShown(false);
            return;
        }

        // An outage replaces the views with the error panel. Sign-in is a
        // view of its own.
        fatalNode.style.display = outage ? 'block' : 'none';
        viewsNode.style.display = outage ? 'none' : 'block';
        if (outage) {
            panelNode.style.height = '';
            renderFatal();
            setPanelShown(true);
            return;
        }
        if (opening) viewsNode.classList.add('no-slide');
        applyViewClasses();
        if (opening) {
            void viewsNode.offsetHeight;
            viewsNode.classList.remove('no-slide');
        }
        if (state.view === 'signin') renderSignIn();

        // header
        if (lock) {
            const broken = lock.state === 'broken';
            const title = broken
                ? 'Review'
                : lock.label || 'This preview’s card';
            titleNode.style.display = '';
            destNode.style.display = 'none';
            titleNode.textContent = title;
            titleNode.title = title;
            const sub = broken
                ? 'This preview’s card is closed or gone, so notes cannot be saved here.'
                : lock.state === 'ok'
                  ? cardLine()
                  : '';
            subNode.textContent = sub;
            subNode.style.display = sub ? '' : 'none';
        } else {
            const target = activeTarget();
            const text = target ? targetLabel(target) : 'Choose where notes go';
            titleNode.style.display = 'none';
            destNode.style.display = '';
            $('lp-dest-text').textContent = text;
            destNode.title = `${text}. Change where notes go.`;
            const line = target ? targetLine(target) : '';
            subNode.textContent = line;
            subNode.style.display = line ? '' : 'none';
        }
        $('lp-compose-title').textContent =
            state.editId != null ? 'Edit note' : 'New note';

        // composer
        const newNote = composing() && state.editId == null;
        if (state.view === 'destination') renderPickerMode();
        renderVerdict();
        renderContext(newNote);
        saveBtn.disabled = saveDisabled();
        saveBtn.innerHTML = state.saving
            ? `<span class="lp-spin"></span>Saving…`
            : 'Save note';
        if (composing()) {
            const ct = state.composeTarget || { type: 'general' };
            // The drawing gets a chip of its own, so it stays visible and removable
            // after the reviewer leaves draw mode and goes back to typing.
            const strokeCount = state.editId == null ? state.strokes.length : 0;
            const strokeChip = strokeCount
                ? `<span class="lp-compose-chip"><span>${
                      strokeCount === 1 ? '1 stroke' : `${strokeCount} strokes`
                  }</span><button class="lp-chip-x" type="button" data-stroke-clear="1" aria-label="Remove the strokes">${ICON.close(12)}</button></span>`
                : '';
            // One chip per anchor. An edit PATCHes the body alone and leaves the
            // stored anchors untouched, so the remove button is not offered while
            // editing: it would confirm a change that the save then discards.
            const anchors = ct.type === 'general' ? [] : ct.anchors || [];
            const editable = state.editId == null;
            const chips = anchors
                .map((anchor, anchorIndex) => {
                    const quoted = !!anchor.quote;
                    const label =
                        anchor.label ||
                        (quoted ? 'Selected text' : 'Selected element');
                    const noun = quoted ? 'text' : 'element';
                    return `<span class="lp-compose-chip" data-anchor-chip="${anchorIndex}" data-anchor-kind="${
                        quoted ? 'quote' : 'element'
                    }"><button class="lp-chip-name" type="button" data-anchor-pill="${anchorIndex}" aria-label="Highlight ${escapeHtml(
                        label,
                    )}"><span>${escapeHtml(label)}</span></button>${
                        editable
                            ? `<button class="lp-chip-x" data-anchor-remove="${anchorIndex}" aria-label="Remove this ${noun}">${ICON.close(12)}</button>`
                            : ''
                    }</span>`;
                })
                .join('');
            const canAdd =
                editable && anchors.length > 0 && anchors.length < MAX_ANCHORS;
            const headHtml =
                chips +
                strokeChip +
                (canAdd
                    ? `<button class="lp-compose-add" type="button" data-anchor-add="1">+ Element <span class="lp-kbd">${MOD_LABEL} hold</span></button>`
                    : '');
            composeHead.style.display = headHtml ? 'flex' : 'none';
            // A repaint that changes nothing keeps the nodes. Replacing them takes the
            // focus off a pill and the hover off a chip, and no event comes to restore it.
            if (headHtml !== composeHeadHtml) {
                composeHeadHtml = headHtml;
                composeHead.innerHTML = headHtml;
                composeHead
                    .querySelectorAll('[data-anchor-remove]')
                    .forEach((button) => {
                        button.addEventListener('mousedown', (event) =>
                            event.preventDefault(),
                        );
                        button.addEventListener('click', () => {
                            removeComposeAnchor(
                                Number(button.dataset.anchorRemove),
                            );
                            if (composing()) focusTextarea();
                        });
                    });
                // Pointing at a pill emphasises the one element it names, and the rest
                // of the comment's anchors stay painted around it. Bound to the whole
                // pill, so reaching for its × keeps the element it removes emphasised.
                composeHead
                    .querySelectorAll('[data-anchor-chip]')
                    .forEach((chip) => {
                        const anchorIndex = Number(chip.dataset.anchorChip);
                        chip.addEventListener('mouseenter', () =>
                            setHoverAnchor(anchorIndex),
                        );
                        chip.addEventListener('mouseleave', () =>
                            setHoverAnchor(null),
                        );
                        // focusin and focusout bubble, so they see the label and the ×. Moving
                        // between the two must not blink the emphasis off on the way.
                        chip.addEventListener('focusin', () =>
                            setHoverAnchor(anchorIndex),
                        );
                        chip.addEventListener('focusout', (event) => {
                            if (!chip.contains(event.relatedTarget))
                                setHoverAnchor(null);
                        });
                    });
                composeHead
                    .querySelectorAll('[data-anchor-pill]')
                    .forEach((button) => {
                        // A press must not take the caret out of the draft. The pill stays
                        // focusable, so only the keyboard reaches it and only it sees a ring.
                        button.addEventListener('mousedown', (event) =>
                            event.preventDefault(),
                        );
                        button.addEventListener('click', () =>
                            showAnchor(Number(button.dataset.anchorPill)),
                        );
                    });
                composeHead
                    .querySelectorAll('[data-stroke-clear]')
                    .forEach((button) => {
                        button.addEventListener('mousedown', (event) =>
                            event.preventDefault(),
                        );
                        button.addEventListener('click', () => clearStrokes());
                    });
                composeHead
                    .querySelectorAll('[data-anchor-add]')
                    .forEach((button) => {
                        button.addEventListener('mousedown', (event) =>
                            event.preventDefault(),
                        );
                        button.addEventListener('click', () =>
                            $('lp-compose-target').click(),
                        );
                    });
            }
            paintAnchorChips();
        }
        // Composer just closed but the textarea kept focus would keep isTyping() true and
        // trap the single-key shortcuts (t/c). Blur it once the composer is hidden.
        if (state.view !== 'compose' && root.activeElement === textareaNode)
            textareaNode.blur();

        // tool buttons
        generalBtn.classList.toggle('active', noteActive);
        generalBtn.setAttribute('aria-pressed', noteActive ? 'true' : 'false');
        targetBtns.forEach((button) => {
            button.classList.toggle('active', state.target);
            button.classList.toggle('on', state.target);
            button.setAttribute(
                'aria-pressed',
                state.target ? 'true' : 'false',
            );
        });
        drawBtns.forEach((button) => {
            // Hidden rather than disabled when the instance offers no drawing: the
            // two remaining modes then split the row, and there is nothing to explain.
            button.style.display = drawingEnabled ? '' : 'none';
            button.classList.toggle('active', state.drawing);
            button.classList.toggle('on', state.drawing);
            button.setAttribute(
                'aria-pressed',
                state.drawing ? 'true' : 'false',
            );
            // An edit PATCHes the body alone, so a drawing added here would be dropped
            // by the save, the same way an anchor change is.
            button.disabled = state.editId != null;
        });

        // The error shows in the view the reviewer is looking at. A note
        // refused for its destination keeps its error in the note view too,
        // for when the destination view closes.
        const errorShown = {
            list: state.view === 'list',
            compose:
                state.view === 'compose' ||
                (state.view === 'destination' && composing()),
            destination: state.view === 'destination',
        };
        Object.entries(errorNodes).forEach(([name, node]) => {
            if (state.actionError && errorShown[name]) {
                node.style.display = 'flex';
                node.innerHTML =
                    `<span>${escapeHtml(errorText(state.actionError))}</span>` +
                    `<button${name === 'compose' ? ' id="lp-error-dismiss"' : ''}>Dismiss</button>`;
                node.querySelector('button').addEventListener('click', () => {
                    state.actionError = null;
                    updatePanel();
                });
            } else {
                node.style.display = 'none';
                node.textContent = '';
            }
        });

        // empty vs list
        emptyNode.style.display = n === 0 ? 'flex' : 'none';
        renderList();

        // footer
        const action = footerAction();
        footerNode.style.display = action ? 'flex' : 'none';
        renderFooterAction(action);

        fitPanel(opening);
        setPanelShown(true);
    };

    let footerActionDrawn = null;
    const renderFooterAction = (action) => {
        const key = JSON.stringify(action);
        if (key === footerActionDrawn) return;
        footerActionDrawn = key;
        footAction.textContent = '';
        if (!action) return;
        if (action.kind === 'finish') {
            const button = document.createElement('button');
            button.type = 'button';
            button.id = 'lp-finish';
            button.className = 'lp-foot-action primary';
            button.textContent = 'Finish review';
            button.addEventListener('click', openFinish);
            footAction.appendChild(button);

            return;
        }
        const link = document.createElement('a');
        link.id = 'lp-open-card';
        link.className = 'lp-foot-action secondary';
        link.target = '_blank';
        link.rel = 'noopener';
        link.href = action.url;
        link.innerHTML = `${escapeHtml(action.text)}${ICON.arrowOut(14)}`;
        footAction.appendChild(link);
    };

    // List rows are reconciled (not rebuilt), so a repaint keeps a row's node,
    // its focus and its row-in animation.
    const rowNodes = new Map();
    const buildSection = (label) => {
        const node = document.createElement('div');
        node.className = 'lp-section';
        node.innerHTML = `<span>${label}</span><span></span>`;

        return node;
    };
    const sectionNodes = {
        here: buildSection('On this page'),
        other: buildSection('Other pages'),
    };
    const renderList = () => {
        rowNodes.forEach((row, index) => {
            if (index >= comments.length) {
                row.remove();
                rowNodes.delete(index);
            }
        });
        const order = displayOrder();
        comments.forEach((comment, index) => {
            let row = rowNodes.get(index);
            if (!row) {
                row = document.createElement('div');
                row.className = 'lp-item';
                row.innerHTML =
                    `<span class="lp-badge"></span>` +
                    `<div class="lp-item-body"><div class="lp-item-text"></div><div class="lp-item-meta"><span class="lp-chip" style="display:none"></span>` +
                    `<button class="lp-item-page" style="display:none" aria-label="Go to the page this note was made on">` +
                    `<span class="lp-item-page-label"></span>${ICON.arrowOut(12)}</button></div></div>` +
                    `<button class="lp-row-menu-btn" aria-label="Note actions" aria-haspopup="menu" aria-expanded="false">${ICON.more(16)}</button>`;
                row.addEventListener('mouseenter', () => {
                    if (
                        state.target ||
                        !comments[index] ||
                        !anchorsOf(comments[index]).length
                    )
                        return;
                    state.hoverId = index;
                    updateHighlight();
                });
                row.addEventListener('mouseleave', () => {
                    state.hoverId = null;
                    updateHighlight();
                });
                row.querySelector('.lp-item-page').addEventListener(
                    'click',
                    () => {
                        const target = comments[index];
                        if (target && target.url) location.href = target.url;
                    },
                );
                row.querySelector('.lp-row-menu-btn').addEventListener(
                    'click',
                    (event) => openRowMenu(event.currentTarget, index),
                );
                rowNodes.set(index, row);
            }
            const anchors = anchorsOf(comment);
            const isElement = anchors.length > 0;
            const here = isHere(comment);
            const badge = row.querySelector('.lp-badge');
            badge.className =
                'lp-badge ' +
                (!here ? 'off' : isElement ? 'element' : 'general');
            badge.textContent = String(order.all.indexOf(index) + 1);
            row.querySelector('.lp-item-text').textContent = comment.body;
            const chipEl = row.querySelector('.lp-chip');
            // A multi-anchor row names its count, and says so when some of its
            // elements are missing, rather than showing one element's text.
            const { degraded, found } = anchorHealth(comment);
            const strokeCount = strokesOf(comment).length;
            let label;
            if (anchors.length > 1) {
                label = degraded
                    ? `${found.length} of ${anchors.length} elements found`
                    : `${anchors.length} elements`;
            } else if (isElement) {
                label = anchorLabel(anchors[0]);
            } else if (strokeCount) {
                label =
                    strokeCount === 1 ? '1 stroke' : `${strokeCount} strokes`;
            } else {
                label = 'Whole page';
            }
            // A note from another page shows that page alone, as a link.
            chipEl.style.display = here && label ? '' : 'none';
            chipEl.classList.toggle('degraded', isElement && degraded);
            if (here && label) {
                const icon = degraded
                    ? ICON.alertCircle(12)
                    : isElement && !anchors[0].quote
                      ? ICON.target(12)
                      : '';
                chipEl.innerHTML = icon;
                chipEl.append(label);
            }
            // Only cross-page comments get the affordance: on the current page the row
            // already highlights its element, and a label saying "here" is noise.
            const pageEl = row.querySelector('.lp-item-page');
            pageEl.style.display = here ? 'none' : '';
            if (!here)
                pageEl.querySelector('.lp-item-page-label').textContent =
                    pageLabel(comment.url);
        });
        // Reordered only when the sequence changed, so a repaint that changes
        // nothing keeps focus and the animation of a row's confirm overlay.
        sectionNodes.here.lastChild.textContent = String(order.here.length);
        sectionNodes.other.lastChild.textContent = String(order.other.length);
        const wanted = [];
        if (order.here.length) {
            wanted.push(
                sectionNodes.here,
                ...order.here.map((index) => rowNodes.get(index)),
            );
        }
        if (order.other.length) {
            wanted.push(
                sectionNodes.other,
                ...order.other.map((index) => rowNodes.get(index)),
            );
        }
        const current = Array.from(listNode.children);
        if (
            current.length !== wanted.length ||
            current.some((node, at) => node !== wanted[at])
        ) {
            listNode.replaceChildren(...wanted);
        }
    };

    // The critical state for a rejected token. Built once and sticky — a bad token cannot
    // recover without a fresh page load (with a corrected embed), so there is nothing to
    // retry or dismiss; the copy tells the embedder how to fix it. A signed-out
    // reviewer sees the sign-in view instead, which renderSignIn fills in.
    const renderFatal = () => {
        const shown = JSON.stringify(state.fatal || {});
        if (fatalNode.dataset.shown === shown) return;
        fatalNode.dataset.shown = shown;
        fatalNode.innerHTML = `<div class="lp-fatal">
        <div class="lp-fatal-disc">${ICON.alert(22)}</div>
        <div class="lp-fatal-title">This review widget can’t connect</div>
        <div class="lp-fatal-sub">${fatalDetail(state.fatal || {})}</div>
      </div>`;
    };
    const renderSignIn = () => {
        const note = $('lp-signin-note');
        const message = (state.fatal && state.fatal.message) || '';
        note.textContent = message;
        note.style.display = message ? '' : 'none';
    };

    const signedOut = () => !!state.fatal && state.fatal.code === 'signed_out';
    const showSignedOut = (message) => {
        enterFatal(signedOutError(message));
        sync();
    };

    // The popup must open inside the click, or a pop-up blocker eats it, and
    // the PKCE digest is async. So it opens blank and navigates once ready.
    let pendingSignIn = null;
    const signIn = async () => {
        const popup = window.open(
            '',
            'loupe-site-review-sign-in',
            'popup,width=480,height=720',
        );
        if (!popup) {
            showSignedOut(
                'Your browser blocked the sign-in window. Allow pop-ups for this site, then try again.',
            );
            return;
        }
        const verifier = randomToken(32);
        const oauthState = randomToken(16);
        let challenge;
        try {
            challenge = await pkceChallenge(verifier);
        } catch {
            popup.close();
            showSignedOut(
                'This page cannot sign in securely. Open it over https.',
            );
            return;
        }
        pendingSignIn = { verifier, state: oauthState };
        popup.location.href = `${BACKEND}/oauth/authorize?${new URLSearchParams(
            {
                response_type: 'code',
                client_id: OAUTH_CLIENT_ID,
                redirect_uri: OAUTH_REDIRECT_URI,
                scope: 'site-review',
                state: oauthState,
                code_challenge: challenge,
                code_challenge_method: 'S256',
                project: PROJECT,
                origin: window.location.origin,
            },
        ).toString()}`;
    };

    // Only Loupe's own callback page may answer, and only with the state this
    // page sent. Anything else is somebody else's message and is ignored.
    const receiveSignIn = async (event) => {
        const pending = pendingSignIn;
        const data = event.data;
        if (
            !pending ||
            event.origin !== BACKEND ||
            !data ||
            data.type !== OAUTH_MESSAGE_TYPE ||
            data.state !== pending.state
        )
            return;
        pendingSignIn = null;
        if (data.iss !== BACKEND) {
            showSignedOut(
                'The sign-in answer came from an unexpected server. Try again.',
            );
            return;
        }
        if (typeof data.code !== 'string' || data.error) {
            showSignedOut(
                'access_denied' === data.error
                    ? 'Sign-in was cancelled. Your notes go to the Loupe project of this site.'
                    : 'Sign-in failed. Try again.',
            );
            return;
        }
        try {
            setGrant(
                await tokenRequest({
                    grant_type: 'authorization_code',
                    code: data.code,
                    redirect_uri: OAUTH_REDIRECT_URI,
                    code_verifier: pending.verifier,
                }),
            );
        } catch {
            showSignedOut('Sign-in failed. Try again.');
            return;
        }
        state.fatal = null;
        resetView();
        await refresh({ firstLoad: true });
        sync();
    };
    if (OAUTH) window.addEventListener('message', receiveSignIn);

    // ---- verdict ----
    // A preview page locks a card, and a reviewer may then send a verdict on it
    // to the card's open GitHub pull requests. The server decides what a send
    // does; the widget records nothing until Send.
    const VERDICT_KINDS = {
        approve: 'Approve',
        'request-changes': 'Request changes',
        comment: 'Comment',
    };
    const VERDICT_CONNECT_WRITE = 'post-review';
    const GITHUB_MESSAGE_TYPE = 'loupe-github-connect';
    const DELIVERY_STATES = {
        pending: 'Waiting to post',
        posted: 'Review posted',
        commented: 'Posted as a comment',
        skipped: 'Not posted',
        refused: 'Not posted',
    };
    const DELIVERY_REASONS = {
        'connection-expired': 'Your GitHub connection ended.',
        'not-open': 'The pull request is no longer open.',
        'no-poster': 'This pull request’s host takes no reviews yet.',
        permission: 'GitHub does not let you review this pull request.',
        empty_body: 'The review had no text.',
    };
    const VERDICT_ERRORS = {
        message_required: 'Write a message before you send.',
        card_closed: 'This card is closed, so it takes no verdict.',
        card_not_found: 'This card is gone.',
        submission_reused:
            'This verdict changed after a first try. Send it again.',
        pull_request_not_on_card:
            'A pull request you picked is no longer on this card. The list is up to date now.',
    };

    const verdict = {
        cardId: null,
        data: null,
        kind: null, // the verdict being written, or null while none is picked
        ticked: [],
        message: '',
        sending: false,
        connecting: false,
        error: null,
        attempt: null,
    };
    let verdictGeneration = 0;
    let verdictDrawn = null;
    const resetVerdict = () => {
        verdictGeneration++;
        Object.assign(verdict, {
            cardId: null,
            data: null,
            kind: null,
            ticked: [],
            message: '',
            sending: false,
            connecting: false,
            error: null,
            attempt: null,
        });
    };

    const newSubmissionId = () => {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));
        return [
            hex.slice(0, 4),
            hex.slice(4, 6),
            hex.slice(6, 8),
            hex.slice(8, 10),
            hex.slice(10),
        ]
            .map((part) => part.join(''))
            .join('-');
    };
    // A retry of the same content keeps its id, so the server can tell it from
    // a second verdict. Any edit makes a new id.
    const submissionIdFor = (content) => {
        const key = JSON.stringify(content);
        if (!verdict.attempt || verdict.attempt.key !== key) {
            verdict.attempt = { key, id: newSubmissionId() };
        }
        return verdict.attempt.id;
    };

    // Request changes when notes are open, and Approve when none is.
    const currentKind = () =>
        verdict.kind ||
        (verdict.data && verdict.data.notes.length > 0
            ? 'request-changes'
            : 'approve');
    const verdictActions = (data, kind) =>
        ((data.preview || {})[kind] || {}).actions || [];
    // The review write needs the reviewer's own GitHub account.
    const verdictNeedsConnect = (data, kind) =>
        data.connection.state !== 'connected' &&
        verdictActions(data, kind).some(
            (action) => action.code === VERDICT_CONNECT_WRITE,
        );
    const verdictCanSend = ({ kind, noteCount, message, ticked, sending }) =>
        !sending &&
        ticked > 0 &&
        (kind === 'approve' || noteCount > 0 || message.trim() !== '');
    const notesOpenText = (kind, count) =>
        kind === 'approve'
            ? `Notes still open · ${count}`
            : `Notes in this review · ${count}`;
    const deliveryText = (delivery) => {
        const head = DELIVERY_STATES[delivery.state] || delivery.state;
        let reason = DELIVERY_REASONS[delivery.reason] || '';
        if (!reason && delivery.state === 'refused') {
            reason = 'GitHub did not take the review.';
        }
        if (!reason && delivery.state === 'skipped' && !delivery.reason) {
            reason = 'Posting reviews is off for this project.';
        }
        return reason ? `${head}. ${reason}` : head;
    };

    // Whether the card the lock names is the one the loaded answer describes.
    const verdictCurrent = () =>
        !!lock &&
        lock.state === 'ok' &&
        verdict.cardId === lock.cardId &&
        !!verdict.data;

    const tidyTicks = () => {
        const ids = verdict.data.pullRequests.map((pr) => pr.id);
        verdict.ticked = verdict.ticked.filter((id) => ids.includes(id));
        if (ids.length === 1) verdict.ticked = ids;
    };

    const loadVerdict = async () => {
        if (!lock || lock.state !== 'ok') {
            if (verdict.cardId !== null) {
                resetVerdict();
                sync();
            }
            return;
        }
        const cardId = lock.cardId;
        if (verdict.cardId !== cardId) resetVerdict();
        verdict.cardId = cardId;
        const generation = ++verdictGeneration;
        try {
            const data = await api(
                'GET',
                `/api/v1/live-review-widget/cards/${encodeURIComponent(cardId)}/verdict`,
            );
            if (generation !== verdictGeneration) return;
            verdict.data = {
                connection: data.connection || { state: 'none' },
                pullRequests: data.pullRequests || [],
                notes: data.notes || [],
                preview: data.preview || {},
                latestVerdict: data.latestVerdict || null,
            };
            tidyTicks();
        } catch {
            // The card still works without a verdict panel, so a failed read
            // hides the panel rather than breaking the widget.
            if (generation !== verdictGeneration) return;
            verdict.data = null;
            verdict.cardId = null;
        }
        sync();
    };
    // Load once per locked card. A send or a connect reloads on its own.
    const followLockForVerdict = () => {
        if (!lock || lock.state !== 'ok') {
            if (verdict.cardId !== null) loadVerdict();
        } else if (verdict.cardId !== lock.cardId) loadVerdict();
    };

    // The footer action opens the view with the verdict that the open notes
    // suggest. The reviewer's own pick, once made, stays.
    const openFinish = () => {
        if (!verdictCurrent()) return;
        verdict.kind = null;
        verdict.error = null;
        tidyTicks();
        showView('finish');
        sync();
        focusVerdictMessage();
        // The notes may have changed since the page loaded.
        loadVerdict();
    };
    const focusVerdictMessage = () => {
        const message = $('lp-verdict-message');
        if (message) message.focus({ preventScroll: true });
    };
    // Focus goes back to the footer action that opened the view.
    const focusFooterAction = () => {
        const action = footAction.querySelector('button, a');
        if (action) action.focus({ preventScroll: true });
    };
    const leaveFinish = () => {
        resetView();
        sync();
        focusFooterAction();
    };
    const verdictSendable = () =>
        verdictCanSend({
            kind: currentKind(),
            noteCount: verdict.data.notes.length,
            message: verdict.message,
            ticked: verdict.ticked.length,
            sending: verdict.sending,
        });

    const sendVerdict = async () => {
        if (!verdictCurrent() || !verdictSendable()) return;
        const cardId = verdict.cardId;
        const kind = currentKind();
        const content = {
            cardId,
            kind,
            pullRequestIds: [...verdict.ticked].sort(),
            message: verdict.message.trim(),
        };
        const submissionId = submissionIdFor(content);
        verdict.sending = true;
        verdict.error = null;
        sync();
        try {
            await api(
                'POST',
                `/api/v1/live-review-widget/cards/${encodeURIComponent(cardId)}/verdicts`,
                {
                    kind,
                    pullRequestIds: verdict.ticked,
                    message: content.message,
                    submissionId,
                },
            );
        } catch (error) {
            if (verdict.cardId !== cardId) return;
            verdict.sending = false;
            if (authFailed(error)) enterFatal(error);
            else {
                verdict.error =
                    VERDICT_ERRORS[error.code] ||
                    'The verdict was not sent. Try again.';
            }
            sync();
            if (
                error.code === 'pull_request_not_on_card' ||
                error.code === 'message_required'
            )
                loadVerdict();
            return;
        }
        if (verdict.cardId !== cardId) return;
        verdict.sending = false;
        verdict.kind = null;
        verdict.message = '';
        verdict.ticked = [];
        verdict.attempt = null;
        // The review reaches GitHub after the answer, so the toast says where
        // it goes rather than that it landed.
        const toGitHub = verdictActions(verdict.data, kind).some(
            (action) => action.code === VERDICT_CONNECT_WRITE,
        );
        const focusBack = viewNodes.finish.contains(root.activeElement);
        resetView();
        flashSaved(
            `Review sent${toGitHub ? ' to GitHub' : ''}: ${(VERDICT_WORDS[kind] || kind).toLowerCase()}`,
        );
        sync();
        if (focusBack) focusFooterAction();
        await loadVerdict();
    };

    // The popup opens inside the click, or a pop-up blocker eats it. Neither
    // the message nor the close is trusted: both only trigger a fresh read.
    let githubPopup = null;
    let githubWatch = 0;
    const finishConnect = () => {
        clearInterval(githubWatch);
        githubPopup = null;
        verdict.connecting = false;
        sync();
        loadVerdict();
    };
    const connectGitHub = () => {
        const popup = window.open(
            `${BACKEND}/account/github/connect?${new URLSearchParams({
                project: PROJECT,
                origin: window.location.origin,
            }).toString()}`,
            'loupe-site-review-github',
            'popup,width=520,height=720',
        );
        if (!popup) {
            verdict.error =
                'Your browser blocked the GitHub window. Allow pop-ups for this site, then try again.';
            sync();
            return;
        }
        githubPopup = popup;
        verdict.connecting = true;
        verdict.error = null;
        clearInterval(githubWatch);
        githubWatch = setInterval(() => {
            if (githubPopup && githubPopup.closed) finishConnect();
        }, 500);
        sync();
    };
    if (OAUTH) {
        window.addEventListener('message', (event) => {
            if (
                !githubPopup ||
                event.origin !== BACKEND ||
                !event.data ||
                event.data.type !== GITHUB_MESSAGE_TYPE
            )
                return;
            finishConnect();
        });
    }

    const VERDICT_HINTS = {
        approve: 'The change is ready from your side.',
        'request-changes': 'The agent works through your notes next.',
        comment: 'Send the notes with no verdict.',
    };

    const messageHint = (kind, noteCount) =>
        noteCount > 0
            ? 'Your notes can stand on their own.'
            : kind === 'approve'
              ? 'Add a message, or send the approval as is.'
              : 'Say what needs to change.';

    const renderVerdictBody = (data) => {
        if (!data.pullRequests.length) return renderVerdictResult(data);
        const kind = currentKind();
        const single = data.pullRequests.length === 1;
        const options = Object.entries(VERDICT_KINDS)
            .map(
                ([value, text]) =>
                    `<label class="lp-verdict-opt${value === kind ? ' is-on' : ''}"><input type="radio" name="lp-verdict-kind" data-kind="${value}"${
                        value === kind ? ' checked' : ''
                    }><span><span class="lp-verdict-opt-t">${text}</span><span class="lp-verdict-opt-d">${VERDICT_HINTS[value]}</span></span></label>`,
            )
            .join('');
        const prs = data.pullRequests
            .map((pr) =>
                single
                    ? ''
                    : `<label class="lp-verdict-pr"><input type="checkbox" data-pr="${escapeHtml(pr.id)}"${
                          verdict.ticked.includes(pr.id) ? ' checked' : ''
                      }>${escapeHtml(pr.label)}</label>`,
            )
            .join('');
        const own = data.pullRequests.some(
            (pr) => pr.ownPullRequest && verdict.ticked.includes(pr.id),
        );
        const optional = kind === 'approve' || data.notes.length > 0;
        const notes = data.notes.length
            ? `<div><div class="lp-verdict-label">${notesOpenText(kind, data.notes.length)}</div><div class="lp-verdict-notes">${data.notes
                  .map(
                      (note, index) =>
                          `<div class="lp-verdict-note"><span class="lp-verdict-n${isHere(note) ? '' : ' off'}">${index + 1}</span><span>${escapeHtml(note.body)}</span></div>`,
                  )
                  .join('')}</div></div>`
            : '';
        const actions = verdictActions(data, kind);
        const does = actions.length
            ? actions
                  .map(
                      (action) =>
                          `<div class="lp-verdict-line">${escapeHtml(action.label)}</div>`,
                  )
                  .join('')
            : '<div class="lp-verdict-line">Recorded in Loupe only</div>';
        const login = data.connection.login;
        const account =
            data.connection.state === 'connected' && login
                ? `<div class="lp-verdict-line">Posts to GitHub as @${escapeHtml(login)}</div>`
                : '';

        return `<fieldset class="lp-verdict-set" aria-label="Verdict" role="group">
          <legend class="lp-verdict-label">Your verdict</legend>
          ${options}
        </fieldset>
        ${prs ? `<div>${prs}</div>` : ''}
        ${own ? '<div class="lp-verdict-warn">You opened this pull request, so GitHub accepts no verdict from you. The review goes as a comment.</div>' : ''}
        <div>
          <label class="lp-verdict-label" for="lp-verdict-message">${optional ? 'Message (optional)' : 'Message'}</label>
          <textarea class="lp-textarea" id="lp-verdict-message" placeholder="${messageHint(kind, data.notes.length)}"></textarea>
        </div>
        ${notes}
        <div>${does}${account}</div>
        ${verdict.error ? `<div class="lp-verdict-error" role="alert">${escapeHtml(verdict.error)}</div>` : ''}
        ${renderVerdictResult(data)}`;
    };

    const renderVerdictFoot = (data) => {
        if (!data.pullRequests.length) return '';
        const connect = verdictNeedsConnect(data, currentKind());
        const send = connect
            ? `<button class="lp-foot-action primary" id="lp-verdict-connect" type="button"${
                  verdict.connecting ? ' disabled' : ''
              }>${verdict.connecting ? 'Waiting for GitHub…' : 'Connect GitHub to send'}</button>`
            : `<button class="lp-foot-action primary" id="lp-verdict-send" type="button"${
                  verdictSendable() ? '' : ' disabled'
              }>${verdict.sending ? '<span class="lp-spin"></span>Sending…' : 'Send review'}</button>`;

        return `<button class="lp-ghost" id="lp-verdict-cancel" type="button">Cancel</button>${send}`;
    };

    const renderVerdictResult = (data) => {
        const latest = data.latestVerdict;
        if (!latest) return '';
        const when = new Date(latest.createdAt);
        const time = Number.isNaN(when.getTime())
            ? ''
            : `, ${when.toLocaleString()}`;
        let expired = false;
        const deliveries = (latest.deliveries || [])
            .map((delivery) => {
                expired = expired || delivery.reason === 'connection-expired';
                const link =
                    typeof delivery.reviewUrl === 'string' &&
                    /^https:\/\//.test(delivery.reviewUrl)
                        ? ` <a href="${escapeHtml(delivery.reviewUrl)}" target="_blank" rel="noopener">View review</a>`
                        : '';
                return `<div>${escapeHtml(delivery.label)}: ${escapeHtml(deliveryText(delivery))}${link}</div>`;
            })
            .join('');
        const again = expired
            ? ' <button class="lp-ghost-sm" id="lp-verdict-again" type="button">Connect again</button>'
            : '';
        return `<div class="lp-verdict-result" id="lp-verdict-result">Last verdict: ${escapeHtml(VERDICT_KINDS[latest.kind] || latest.kind)}${escapeHtml(time)}${deliveries}${again}</div>`;
    };

    const renderVerdict = () => {
        const data = verdictCurrent() ? verdict.data : null;
        if (!data) {
            verdictDrawn = null;
            return;
        }
        finishSub.textContent = `${lock && lock.label ? lock.label : 'This card'} · ${pullRequestName(data.pullRequests)}`;
        const body = renderVerdictBody(data);
        const foot = renderVerdictFoot(data);
        // The typed message is not part of the markup, so typing never repaints.
        if (body + foot === verdictDrawn) return;
        verdictDrawn = body + foot;
        const focused = root.activeElement;
        const typing = focused?.id === 'lp-verdict-message';
        const pickedKind = focused?.dataset?.kind;
        const tickedBox = focused?.dataset?.pr;
        verdictNode.innerHTML = body;
        verdictFoot.innerHTML = foot;
        verdictNode.querySelectorAll('[data-kind]').forEach((radio) =>
            radio.addEventListener('change', () => {
                verdict.kind = radio.dataset.kind;
                verdict.error = null;
                sync();
            }),
        );
        verdictNode.querySelectorAll('[data-pr]').forEach((box) =>
            box.addEventListener('change', () => {
                verdict.ticked = box.checked
                    ? [...verdict.ticked, box.dataset.pr]
                    : verdict.ticked.filter((id) => id !== box.dataset.pr);
                sync();
            }),
        );
        const message = $('lp-verdict-message');
        if (message) {
            message.value = verdict.message;
            message.addEventListener('input', () => {
                verdict.message = message.value;
                const sendButton = $('lp-verdict-send');
                if (sendButton) sendButton.disabled = !verdictSendable();
            });
            if (typing) message.focus({ preventScroll: true });
        }
        if (pickedKind) {
            const radio = verdictNode.querySelector(
                `[data-kind="${CSS.escape(pickedKind)}"]`,
            );
            if (radio) radio.focus({ preventScroll: true });
        }
        if (tickedBox) {
            const box = verdictNode.querySelector(
                `[data-pr="${CSS.escape(tickedBox)}"]`,
            );
            if (box) box.focus({ preventScroll: true });
        }
        const on = (id, handler) => {
            const node = $(id);
            if (node) node.addEventListener('click', handler);
        };
        on('lp-verdict-cancel', leaveFinish);
        on('lp-verdict-send', sendVerdict);
        on('lp-verdict-connect', connectGitHub);
        on('lp-verdict-again', connectGitHub);
    };

    const sync = () => {
        updatePanel();
        renderOverlay();
    };

    // The comment is live the moment the API accepts it, and nothing else on screen
    // says so — the pin and the row look identical to an unsaved one. This is that
    // acknowledgement, on the same toast furniture pick mode uses.
    let savedToastTimer = 0;
    const flashSaved = (text) => {
        state.savedNotice = text;
        clearTimeout(savedToastTimer);
        savedToastTimer = setTimeout(() => {
            state.savedNotice = null;
            renderOverlay();
        }, 2400);
    };

    // ---- composer / focus ----
    // preventScroll, because a view that is still sliding in sits outside the
    // panel's box, and a focus that scrolled to it would shift the whole panel.
    const focusTextarea = () => {
        const t = textareaNode;
        t.focus({ preventScroll: true });
        const end = t.value.length;
        t.setSelectionRange(end, end);
    };
    const openNoteComposer = () => {
        if (state.fatal) return;
        // The last chance to notice a page that swapped its marker after the
        // window the navigation handler watches. It lands exactly where being
        // wrong would cost something, since nothing attaches until a comment is
        // composed, and it costs one querySelector. A MutationObserver over the
        // document would close the same gap on every DOM change of somebody
        // else's page.
        recheckMarker();
        // The panel can open on the destination view. The note goes under it,
        // so the choice returns to the note rather than to the list.
        if (state.view === 'destination') resetView();
        showView('compose');
        state.composeTarget = { type: 'general' };
        state.editId = null;
        state.draft = '';
        state.strokes = [];
        textareaNode.value = '';
        state.open = true;
        setTargeting(false);
        setDrawing(false);
        // Where notes go is the first choice of a review, so the destination
        // view opens over the new note until the reviewer has made it.
        const asking = needsMode();
        if (asking) showView('destination');
        sync();
        if (asking) focusDestination();
        else focusTextarea();
    };
    // Where notes go is the first choice of a review, so a start that would
    // pick or draw opens the composer and its mode picker instead.
    const needsMode = () => !lock && !storedTarget;
    const askModeFirst = () => {
        if (!needsMode()) return false;
        if (composing() && state.editId == null) {
            state.open = true;
            setTargeting(false);
            setDrawing(false);
            showView('destination');
            sync();
            focusDestination();
        } else {
            openNoteComposer();
        }
        return true;
    };
    const toggleNote = () => {
        const ct = state.composeTarget || {};
        if (composing() && ct.type === 'general') {
            resetView();
            state.composeTarget = null;
            state.editId = null;
            state.draft = '';
            state.strokes = [];
            textareaNode.value = '';
            setDrawing(false);
            sync();
        } else {
            openNoteComposer();
        }
    };
    const anchorFor = (el) => ({
        el,
        selector: selectorFor(el),
        // Array.from splits on code points, so a truncation cannot land inside an
        // emoji and produce the broken character the API rejects.
        text: Array.from((el.innerText || '').trim())
            .slice(0, TEXT_MAX)
            .join(''),
        label: firstLineLabel(el.innerText),
    });

    // The element a selection is anchored to: the smallest one holding the whole
    // range. A selection spanning several blocks keeps the wrapper around them,
    // because the quote inside it is still exact. <body> and <html> are refused,
    // because a selector naming either says only what a page note already says.
    const containerElementOf = (range) => {
        let el = range.commonAncestorContainer;
        if (el && el.nodeType !== 1) el = el.parentElement;
        if (!el || el.nodeType !== 1) return null;
        // getRootNode rather than insideWidget alone: Node.contains does not cross a
        // shadow boundary, so text selected in the widget's own panel reads as host
        // page text. A selector cannot reach into any shadow root either, so one
        // belonging to the host page is refused here for the same reason.
        if (el.getRootNode() !== document) return null;
        if (el === document.body || el === document.documentElement)
            return null;
        return insideWidget(el) ? null : el;
    };

    // A selected run of text as an anchor. The quote and its context are sliced
    // from the container's textContent, the same basis quoteRange searches, so
    // capture and match always count the same characters. Not Selection.toString():
    // Chrome puts a break between blocks there, and that string is then not a
    // substring of textContent, so nothing would re-anchor. `text` stays the
    // element's innerText, which is where those breaks survive.
    const anchorForRange = (range) => {
        const el = containerElementOf(range);
        if (!el) return null;
        const haystack = el.textContent || '';
        const start = textOffsetIn(el, range.startContainer, range.startOffset);
        const end = textOffsetIn(el, range.endContainer, range.endOffset);
        if (end <= start) return null;
        const quote = haystack.slice(start, end);
        if (!quote.trim()) return null;
        if (Array.from(quote).length > QUOTE_MAX) return { tooLong: true };
        return {
            ...anchorFor(el),
            range,
            quote,
            quotePrefix: beforeText(haystack, start, QUOTE_CONTEXT),
            quoteSuffix: afterText(haystack, end, QUOTE_CONTEXT),
            label: firstLineLabel(quote),
        };
    };

    // The anchors the composer currently holds, empty for a page note.
    const composeAnchors = () => {
        const ct = state.composeTarget;
        return ct && ct.type === 'element' ? ct.anchors : [];
    };

    // The element alone would swallow a second passage inside an element already
    // pointed at, and the quote alone would swallow the second of two identical
    // phrases — the pair the stored context exists to tell apart. So all three
    // fields decide, joined on NUL, which the HTML parser never leaves in page
    // text and which therefore cannot run two fields into another pair's key.
    const quoteKey = (anchor) =>
        [
            anchor.quote || '',
            anchor.quotePrefix || '',
            anchor.quoteSuffix || '',
        ].join('\u0000');
    const sameAnchor = (a, b) => a.el === b.el && quoteKey(a) === quoteKey(b);

    // Add an anchor to the composer, whether it names an element or a run of text
    // inside one. A pick while a new comment is open extends it rather than
    // replacing its anchor, which is how one comment comes to say something about
    // several things. `keepPicking` holds pick mode open for the next one, which
    // is what the add-another modifier does.
    const addComposeAnchor = (anchor, keepPicking) => {
        if (composing() && state.editId == null) {
            const existing = composeAnchors();
            let stayed = keepPicking;
            if (existing.length >= MAX_ANCHORS) {
                state.actionError = { message: AT_CAP_MESSAGE };
                stayed = false;
            } else if (!existing.some((entry) => sameAnchor(entry, anchor))) {
                state.composeTarget = {
                    type: 'element',
                    anchors: existing.concat([anchor]),
                };
            }
            state.open = true;
            if (!stayed) setTargeting(false);
            state.addAnchor = stayed;
            sync();
            if (!stayed && root.activeElement !== textareaNode)
                focusKeepingPick();
            return;
        }
        // The last chance to notice a page that swapped its marker after the
        // window the navigation handler watches. It lands exactly where being
        // wrong would cost something, since nothing attaches until a comment is
        // composed, and it costs one querySelector. A MutationObserver over the
        // document would close the same gap on every DOM change of somebody
        // else's page.
        recheckMarker();
        showView('compose');
        state.composeTarget = { type: 'element', anchors: [anchor] };
        state.editId = null;
        state.draft = '';
        state.strokes = [];
        textareaNode.value = '';
        state.open = true;
        if (!keepPicking) setTargeting(false);
        state.addAnchor = !!keepPicking;
        sync();
        if (!keepPicking) focusKeepingPick();
    };
    const openElementComposer = (el, keepPicking) =>
        addComposeAnchor(anchorFor(el), keepPicking);

    // Turn the reviewer's selection into a quoted anchor. The offer is what calls
    // this, never the selection itself: acting on every drag would take a gesture
    // the reviewer aimed at the host page.
    const commentOnSelection = () => {
        const anchor = state.quotePick && state.quotePick.anchor;
        clearQuotePick();
        if (!anchor) return;
        if (anchor.tooLong) {
            state.actionError = { message: LONG_QUOTE_MESSAGE };
            state.open = true;
            sync();
            return;
        }
        const selection = document.getSelection();
        if (selection) selection.removeAllRanges();
        addComposeAnchor(anchor, false);
    };

    // Drop one anchor from the composer. Removing the last one leaves the comment
    // as a page note rather than closing the composer under the reviewer.
    const removeComposeAnchor = (anchorIndex) => {
        const anchors = composeAnchors();
        if (!anchors.length || state.editId != null) return;
        anchors.splice(anchorIndex, 1);
        if (!anchors.length) state.composeTarget = { type: 'general' };
        // The index the removed anchor held now belongs to the next one. Emphasis
        // has to go with the anchor that is gone, not pass to its neighbour.
        setHoverAnchor(null);
        sync();
    };

    const paintAnchorChips = () => {
        composeHead.querySelectorAll('[data-anchor-chip]').forEach((chip) => {
            chip.classList.toggle(
                'lit',
                Number(chip.dataset.anchorChip) === state.hoverAnchor,
            );
        });
    };

    // Emphasise one anchor of the comment being composed, on the page and on its
    // pill. Either end can set it: a pill points at an element, and an element's
    // own box points back at its pill.
    const setHoverAnchor = (anchorIndex) => {
        if (state.hoverAnchor === anchorIndex) return;
        state.hoverAnchor = anchorIndex;
        paintAnchorChips();
        updateHighlight();
    };

    // Scroll to an anchor only when the reviewer asks by clicking its pill, and
    // only when it is off screen. Scrolling on hover would move the page under a
    // pointer that is only browsing the pills.
    const showAnchor = (anchorIndex) => {
        const anchor = composeAnchors()[anchorIndex];
        const r = anchor && anchor.el && rectOf(boxOf(anchor));
        if (!r) return;
        const onScreen =
            r.top >= 0 &&
            r.bottom <= window.innerHeight &&
            r.left >= 0 &&
            r.right <= window.innerWidth;
        if (onScreen) return;
        anchor.el.scrollIntoView({
            block: 'center',
            inline: 'center',
            behavior: 'smooth',
        });
    };

    // ---- drawing mode ----
    // A stroke creates no anchor and promotes nothing under it. It says "look
    // here" beside whatever the comment already points at, so a reviewer can
    // target an element and draw an arrow showing where it should move.
    const setDrawing = (on) => {
        if (on && hidden.matches) return;
        if (on) setTargeting(false);
        state.drawing = on;
        if (!on) {
            liveStroke = null;
            return;
        }
        // The boxes stop taking pointer events on this tick, so a hover the pointer
        // is already inside would never see its mouseout and would stay emphasised.
        state.hoverAnchor = null;
    };
    const toggleDraw = () => {
        if (state.fatal || !drawingEnabled) return;
        if (state.drawing) {
            setDrawing(false);
            sync();
            if (composing()) focusKeepingPick();
            return;
        }
        if (state.editId != null) return;
        if (askModeFirst()) return;
        if (!composing()) {
            // Same reason as the other two entry points: a new comment must be
            // attached to the card this page names, not the last one.
            recheckMarker();
            showView('compose');
            state.composeTarget = { type: 'general' };
            state.strokes = [];
            state.draft = '';
            textareaNode.value = '';
        }
        state.open = true;
        setDrawing(true);
        // The reviewer is drawing, not typing: the next ⌘Z has to undo a stroke,
        // and a stray keystroke must not land in a field nobody is looking at.
        textareaNode.blur();
        sync();
    };
    const undoStroke = () => {
        if (!state.strokes.length) return;
        state.strokes.pop();
        sync();
    };
    const clearStrokes = () => {
        if (!state.strokes.length) return;
        state.strokes = [];
        sync();
    };
    const beginStroke = (event) => {
        if (!state.drawing || event.button !== 0 || liveStroke) return;
        if (state.strokes.length >= MAX_STROKES) {
            state.actionError = { message: AT_STROKE_CAP_MESSAGE };
            sync();
            return;
        }
        event.preventDefault();
        // Capture, so a drag that wanders over the panel or leaves the window still
        // ends on this node instead of leaving a stroke open behind the pointer.
        if (canvasNode.setPointerCapture)
            canvasNode.setPointerCapture(event.pointerId);
        liveStroke = [docPointOf(event)];
    };
    const extendStroke = (event) => {
        if (!liveStroke || liveStroke.length >= MAX_STROKE_POINTS) return;
        const point = docPointOf(event);
        const last = liveStroke[liveStroke.length - 1];
        if (
            Math.abs(point[0] - last[0]) + Math.abs(point[1] - last[1]) <
            MIN_POINT_GAP
        )
            return;
        liveStroke.push(point);
        renderStrokes();
    };
    // A press that never moved is not a stroke, and the API refuses a one-point one.
    const endStroke = (keep) => {
        if (!liveStroke) return;
        const points = liveStroke;
        liveStroke = null;
        if (keep && points.length > 1) state.strokes.push(points);
        sync();
    };

    // Add-anchor mode. Holding the modifier over an open composer brings the picker
    // back, so a click adds another anchor without discarding the draft. The mode
    // lasts as long as the hold. At the cap it says so instead of picking.
    // The mode arms on the keydown and shows itself on the pointer. A reveal on
    // the keydown alone flashes the picker on and straight back off for every
    // chord typed into the composer: ⌘Enter to save, ⌘A, ⌘C, ⌘V. The pointer has
    // to move to reach the next element anyway, so nothing is lost.
    const disarmReveal = () => {
        document.removeEventListener('mousemove', revealAddAnchor, true);
        document.removeEventListener('mousedown', revealAddAnchor, true);
    };
    const revealAddAnchor = (event) => {
        // A synthetic move that carries no modifier is not the reviewer's pointer,
        // so it must not spend the arm.
        if (!state.addAnchor || !modHeld(event)) return;
        disarmReveal();
        if (composeAnchors().length >= MAX_ANCHORS) {
            state.actionError = { message: AT_CAP_MESSAGE };
        } else {
            setTargeting(true);
            // A listener added mid-dispatch does not get the event that added it, so
            // the mousedown that revealed the picker would focus the host element
            // `onDown` exists to protect. Run it by hand for that one event.
            if (event.type === 'mousedown') onDown(event);
        }
        sync();
    };
    const enterAddAnchor = () => {
        if (state.modCancelled || state.addAnchor) return;
        if (
            state.fatal ||
            !composing() ||
            state.editId != null ||
            hidden.matches ||
            needsMode()
        )
            return;
        // A modifier held while drawing belongs to the drag, not to the picker.
        if (state.drawing) return;
        state.addAnchor = true;
        document.addEventListener('mousemove', revealAddAnchor, true);
        document.addEventListener('mousedown', revealAddAnchor, true);
    };
    // `cancelled` marks the hold spent, so another key pressed with the modifier
    // down does not re-enter the mode before the reviewer lets go.
    const exitAddAnchor = (cancelled) => {
        state.modCancelled = cancelled;
        disarmReveal();
        if (!state.addAnchor) return;
        state.addAnchor = false;
        setTargeting(false);
        sync();
        if (composing() && root.activeElement !== textareaNode)
            focusKeepingPick();
    };
    // Re-open the composer pre-filled to edit an existing comment in place. The anchors
    // are preserved and rebuilt from storage; only the body is editable.
    const openEditComposer = (index) => {
        const comment = comments[index];
        if (!comment) return;
        // Read the stored anchors rather than the resolved ones: a comment made on
        // another page still has its anchors, and editing must not silently demote
        // it to a page note.
        const stored = anchorsOf(comment).filter((anchor) => anchor.selector);
        const onThisPage = comment.url === location.href;
        showView('compose');
        state.editId = comment.id;
        state.composeTarget = stored.length
            ? {
                  type: 'element',
                  anchors: stored.map((anchor) => {
                      const el = onThisPage
                          ? queryAnchor(anchor.selector, anchor.text)
                          : null; // null off-page — fine
                      return {
                          el,
                          range: quoteRange(el, anchor),
                          selector: anchor.selector,
                          text: anchor.text,
                          quote: anchor.quote,
                          quotePrefix: anchor.quotePrefix,
                          quoteSuffix: anchor.quoteSuffix,
                          label: anchorLabel(anchor),
                      };
                  }),
              }
            : { type: 'general' };
        state.draft = comment.body;
        // An edit PATCHes the body alone, so the stored drawing is left where it
        // is and the composer offers no stroke controls.
        state.strokes = [];
        textareaNode.value = comment.body;
        state.open = true;
        setTargeting(false);
        setDrawing(false);
        sync();
        focusTextarea();
    };
    let pickerDebounce = null;
    let pickerGeneration = 0;
    // The destination view. The radio group holds the choice. A card list
    // loads only once the choice is an existing card. A choice holds for every
    // later note until the reviewer changes it, and it outlives a reload.
    const sameTarget = (a, b) =>
        !!a && !!b && a.mode === b.mode && a.cardId === b.cardId;
    const pickerSubs = { 'per-review': 'new', epic: 'new' };
    let pickerPicked = null;
    // The epic is picked on a second step, after the reviewer chose the mode.
    let pickerEpicStep = false;
    const pickerHasCards = () =>
        pickerMode === 'per-review' || pickerMode === 'epic';
    const pickerShowsCards = () =>
        pickerHasCards() && (pickerMode !== 'epic' || pickerEpicStep);
    const focusDestination = () => {
        const field =
            pickerShowsCards() &&
            (pickerSubs[pickerMode] === 'existing'
                ? pickerSearchNode
                : pickerTitleNode);
        const control =
            field ||
            root.querySelector('#lp-picker-modes input[type="radio"]:checked');
        if (!control) return;
        control.focus({ preventScroll: true });
        if (control === pickerTitleNode) control.select();
    };
    // The view starts on the stored choice, or on one card for the review.
    const initDestination = () => {
        const stored = storedTarget;
        pickerMode = stored ? stored.mode : 'per-review';
        pickerSubs['per-review'] = 'new';
        pickerSubs.epic = 'new';
        pickerEpicStep = false;
        pickerPicked = null;
        pickerError = null;
        pickerSearchNode.value = '';
        pickerTitleNode.value = `Review: ${location.pathname}`;
        if (stored && stored.cardId && pickerHasCards()) {
            pickerSubs[pickerMode] = 'existing';
            pickerPicked = {
                cardId: stored.cardId,
                label: stored.label || '',
                url: stored.url || null,
            };
            loadPickerCards('');
        }
    };
    const openDestination = () => {
        initDestination();
        showView('destination');
        sync();
        focusDestination();
    };
    // Back to whatever opened the destination view: the note being written,
    // or the list.
    const leaveDestination = () => {
        pickerMode = null;
        if (state.view === 'destination') backView();
    };
    // Focus follows the reviewer back, so it never stays in the hidden view.
    const focusAfterDestination = () => {
        if (composing()) focusTextarea();
        else destNode.focus({ preventScroll: true });
    };
    const chooseTarget = (target) => {
        const changed = !sameTarget(storedTarget, target);
        storedTarget = target;
        writeStoredTarget(target);
        leaveDestination();
        if (changed) {
            // The notes of the old destination are not part of the new one.
            writeSessionCards([]);
            comments = [];
            scopeGeneration++;
        }
        sync();
        focusAfterDestination();
        if (changed) refresh().then(sync);
    };
    const selectMode = (mode) => {
        if (pickerMode === mode) return;
        pickerMode = mode;
        pickerEpicStep = false;
        pickerPicked = null;
        pickerError = null;
        if (pickerSubs[mode] === 'existing') loadPickerCards('');
        sync();
    };
    const selectSub = (sub) => {
        if (!pickerHasCards() || pickerSubs[pickerMode] === sub) return;
        pickerSubs[pickerMode] = sub;
        pickerError = null;
        if (sub === 'existing') loadPickerCards(pickerSearchNode.value.trim());
        sync();
        focusDestination();
    };
    root.querySelectorAll('#lp-picker-modes [data-mode]').forEach((option) => {
        const choose = () => selectMode(option.dataset.mode);
        option.addEventListener('click', choose);
        option
            .querySelector('input[type="radio"]')
            .addEventListener('change', choose);
    });
    root.querySelectorAll('#lp-picker-seg [data-sub]').forEach((button) =>
        button.addEventListener('click', () => selectSub(button.dataset.sub)),
    );
    const pickerCanCommit = () => {
        if (!pickerMode || pickerCreating) return false;
        if (pickerMode === 'per-note') return true;
        if (pickerMode === 'epic' && !pickerEpicStep) return true;
        if (pickerSubs[pickerMode] === 'existing') return !!pickerPicked;
        return !!pickerTitleNode.value.trim();
    };
    const renderPickerMode = () => {
        if (!pickerMode) initDestination();
        const epic = pickerMode === 'epic';
        const existing =
            pickerHasCards() && pickerSubs[pickerMode] === 'existing';
        root.querySelectorAll('#lp-picker-modes [data-mode]').forEach(
            (option) => {
                const on = option.dataset.mode === pickerMode;
                option.classList.toggle('is-on', on);
                option.querySelector('input[type="radio"]').checked = on;
            },
        );
        root.querySelector(
            '#lp-picker-modes [data-mode="epic"]',
        ).style.display = parentTypes.length ? '' : 'none';
        $('lp-picker-modes').style.display = pickerEpicStep ? 'none' : '';
        $('lp-destination-title').textContent = pickerEpicStep
            ? 'Pick or create an epic'
            : 'Where should your notes go?';
        const more = $('lp-picker-more');
        more.style.display = pickerShowsCards() ? '' : 'none';
        const slot = pickerShowsCards()
            ? $(`lp-picker-slot-${pickerMode}`)
            : null;
        if (slot && more.parentNode !== slot) slot.appendChild(more);
        const noun = epic ? 'epic' : 'card';
        const labels = [`New ${noun}`, `Existing ${noun}`];
        root.querySelectorAll('#lp-picker-seg [data-sub]').forEach(
            (button, index) => {
                const on = (button.dataset.sub === 'existing') === existing;
                button.textContent = labels[index];
                button.classList.toggle('is-on', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            },
        );
        $('lp-picker-seg').setAttribute('aria-label', epic ? 'Epic' : 'Card');
        $('lp-picker-new').style.display = existing ? 'none' : '';
        $('lp-picker-cards').style.display = existing ? '' : 'none';
        $('lp-picker-title-label').textContent = epic
            ? 'Epic title'
            : 'Card title';
        pickerSearchNode.placeholder = epic ? 'Search epics…' : 'Search cards…';
        pickerSearchNode.setAttribute(
            'aria-label',
            epic ? 'Search epics' : 'Search cards',
        );
        const errorNode = $('lp-picker-error');
        errorNode.textContent = pickerError || '';
        errorNode.style.display = pickerError && !existing ? '' : 'none';
        renderParentTypes();
        renderPicker();
        const exists = !!storedTarget;
        const back = exists || pickerEpicStep;
        $('lp-destination-head').classList.toggle('lp-hd--back', back);
        $('lp-destination-back').style.display = back ? '' : 'none';
        $('lp-destination-back').setAttribute(
            'aria-label',
            pickerEpicStep ? 'Back' : 'Back to notes',
        );
        const save = $('lp-destination-save');
        save.disabled = !pickerCanCommit();
        save.innerHTML = pickerCreating
            ? '<span class="lp-spin"></span>Creating…'
            : epic && !pickerEpicStep
              ? 'Next'
              : exists
                ? 'Save'
                : 'Start reviewing';
    };
    // A new epic needs a type that can have children. The selector shows only
    // for a new epic, and only when the template has more than one.
    const renderParentTypes = () => {
        const keys = parentTypes.map((type) => `${type.key}:${type.label}`);
        const listed = [...pickerTypeNode.options].map(
            (option) => `${option.value}:${option.textContent}`,
        );
        if (keys.join('\n') !== listed.join('\n')) {
            const chosen = pickerTypeNode.value;
            pickerTypeNode.textContent = '';
            parentTypes.forEach((type) => {
                const option = document.createElement('option');
                option.value = type.key;
                option.textContent = type.label;
                pickerTypeNode.appendChild(option);
            });
            if (parentTypes.some((type) => type.key === chosen)) {
                pickerTypeNode.value = chosen;
            }
        }
        const shown =
            pickerMode === 'epic' &&
            pickerSubs.epic === 'new' &&
            parentTypes.length > 1;
        pickerTypeNode.style.display = shown ? '' : 'none';
        $('lp-picker-type-label').style.display = shown ? '' : 'none';
    };
    const renderPicker = () => {
        pickerListNode.textContent = '';
        const note = (text) => {
            const el = document.createElement('div');
            el.className = 'lp-picker-note';
            el.textContent = text;
            pickerListNode.appendChild(el);
        };
        if (pickerBusy) return note('Loading…');
        if (pickerError) return note(pickerError);
        const shown = [...pickerCards];
        // The card this review already uses stays in the list, picked, even
        // when the search does not return it.
        if (
            pickerPicked &&
            pickerPicked.label &&
            !pickerSearchNode.value.trim() &&
            !shown.some((card) => card.cardId === pickerPicked.cardId)
        ) {
            shown.unshift({
                cardId: pickerPicked.cardId,
                label: pickerPicked.label,
            });
        }
        if (!shown.length) {
            return note(
                pickerMode === 'epic'
                    ? 'No open epics match.'
                    : 'No open cards match.',
            );
        }
        shown.forEach((card) => {
            const row = document.createElement('button');
            row.type = 'button';
            const picked =
                !!pickerPicked && pickerPicked.cardId === card.cardId;
            row.className = 'lp-picker-row' + (picked ? ' is-picked' : '');
            row.setAttribute('aria-pressed', picked ? 'true' : 'false');
            if (card.label) {
                row.textContent = card.label;
            } else {
                const n = document.createElement('span');
                n.className = 'n';
                n.textContent = `#${card.number}`;
                const t = document.createElement('span');
                t.className = 't';
                t.textContent = card.title;
                row.append(n, t);
            }
            row.addEventListener('click', () => {
                pickerPicked = {
                    cardId: card.cardId,
                    label: card.label || `#${card.number} ${card.title}`,
                    url: null,
                };
                sync();
            });
            pickerListNode.appendChild(row);
        });
    };
    const loadPickerCards = async (query) => {
        // Debouncing spaces the requests out; it does not order the answers. Two
        // searches can still overlap, and the slower one would otherwise land
        // last and show results for a query the reviewer has moved past.
        const generation = ++pickerGeneration;
        const current = () => generation === pickerGeneration;
        pickerBusy = true;
        pickerError = null;
        renderPicker();
        try {
            const parameters = new URLSearchParams();
            if (query) parameters.set('q', query);
            if (pickerMode === 'epic') parameters.set('parent', '1');
            const search = parameters.toString();
            const path =
                '/api/v1/live-review-widget/cards' +
                (search ? '?' + search : '');
            const answer = await api('GET', path);
            if (!current()) return;
            pickerCards = answer.cards || [];
        } catch (error) {
            if (authFailed(error)) {
                // enterFatal only sets state. Returning without a repaint left
                // a revoked token showing the picker's "Loading…" for ever
                // instead of the critical panel.
                enterFatal(error);
                sync();

                return;
            }
            if (!current()) return;
            pickerCards = [];
            pickerError = 'Could not load cards.';
        }
        pickerBusy = false;
        renderPicker();
    };
    const createCardFromPicker = async () => {
        const title = pickerTitleNode.value.trim();
        const mode = pickerMode;
        if (!title || !mode || pickerCreating) return;
        // A search may still be running, and its result would overwrite this
        // panel underneath the create. Cancelling the timer stops one that has
        // not started; bumping the generation discards one already in flight,
        // which would otherwise replace a creation error with a card list and
        // leave the reviewer believing the card was made.
        clearTimeout(pickerDebounce);
        // The superseded search returns early and never clears its own flag, so
        // a creation error would render behind a "Loading…" that never lifts.
        pickerGeneration++;
        pickerBusy = false;
        pickerCreating = true;
        pickerError = null;
        sync();
        try {
            const card = await api('POST', '/api/v1/live-review-widget/cards', {
                title,
                ...(mode === 'epic'
                    ? {
                          parent: true,
                          ...(parentTypes.length > 1
                              ? { type: pickerTypeNode.value }
                              : {}),
                      }
                    : {}),
            });
            pickerCreating = false;
            chooseTarget({
                mode,
                cardId: card.cardId,
                label: card.label,
                url: card.url,
            });
            return;
        } catch (error) {
            if (authFailed(error)) {
                // enterFatal only sets state. Returning without a repaint left
                // a revoked token showing the picker's "Loading…" for ever
                // instead of the critical panel.
                enterFatal(error);
                sync();

                return;
            }
            pickerError = 'Could not create the card.';
        }
        pickerCreating = false;
        sync();
    };
    // The one action of the view: the choice is made here, not on a row.
    const commitDestination = () => {
        if (!pickerCanCommit()) return;
        if (pickerMode === 'epic' && !pickerEpicStep) {
            pickerEpicStep = true;
            pickerError = null;
            if (pickerSubs.epic === 'existing') {
                loadPickerCards(pickerSearchNode.value.trim());
            }
            sync();
            focusDestination();
            return;
        }
        if (pickerMode === 'per-note') {
            chooseTarget({ mode: 'per-note', cardId: null });
        } else if (pickerSubs[pickerMode] === 'existing') {
            chooseTarget({
                mode: pickerMode,
                cardId: pickerPicked.cardId,
                label: pickerPicked.label,
                url: pickerPicked.url,
            });
        } else {
            createCardFromPicker();
        }
    };

    pickerSearchNode.addEventListener('input', () => {
        clearTimeout(pickerDebounce);
        // Debounced, because this is a keystroke on a public endpoint.
        pickerDebounce = setTimeout(
            () => loadPickerCards(pickerSearchNode.value.trim()),
            250,
        );
    });
    pickerTitleNode.addEventListener('input', () => {
        $('lp-destination-save').disabled = !pickerCanCommit();
    });
    // Escape closes the picker alone. The document's own Escape cancels the
    // compose, which would throw the draft away.
    const closePickerOnEscape = (event) => {
        if (event.key !== 'Escape') return;
        event.stopPropagation();
        leaveDestination();
        sync();
        focusAfterDestination();
    };
    pickerSearchNode.addEventListener('keydown', closePickerOnEscape);
    pickerTitleNode.addEventListener('keydown', closePickerOnEscape);
    pickerTitleNode.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') commitDestination();
    });
    $('lp-destination-save').addEventListener('click', commitDestination);
    // With no destination chosen a note cannot be saved, so backing out of
    // the choice backs out of the note too.
    const cancelDestination = () => {
        if (needsMode() && composing()) {
            cancelCompose();
            return;
        }
        leaveDestination();
        sync();
        focusAfterDestination();
    };
    $('lp-picker-cancel').addEventListener('click', cancelDestination);
    $('lp-destination-back').addEventListener('click', () => {
        if (!pickerEpicStep) {
            cancelDestination();
            return;
        }
        pickerEpicStep = false;
        pickerError = null;
        sync();
        focusDestination();
    });
    $('lp-destination-close').addEventListener('click', () => togglePanel());
    destNode.addEventListener('click', openDestination);

    const cancelCompose = () => {
        state.deliveryId = null;
        resetView();
        state.composeTarget = null;
        state.editId = null;
        pickerMode = null;
        state.draft = '';
        state.strokes = [];
        textareaNode.value = '';
        setDrawing(false);
        sync();
    };
    const newDeliveryId = () => {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, (byte) =>
            byte.toString(16).padStart(2, '0'),
        ).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    // A save refused for its target says the widget's view is stale. The
    // draft stays in the composer, so nothing is lost.
    const forgetRefusedTarget = (error) => {
        if (!error || !/^target_/.test(error.code || '')) return;
        if (lock) {
            lock.state = 'broken';
        } else {
            storedTarget = null;
            writeStoredTarget(null);
            pickerMode = null;
            // The reviewer chooses again, with the note still in the view below.
            showView('destination');
        }
    };
    const saveComment = async () => {
        const body = state.draft.trim();
        if (!body || state.saving) return;
        const editing = state.editId != null;
        let savedLabel;
        state.saving = true;
        state.actionError = null;
        updatePanel();
        try {
            await ready; // don't let the boot refresh clobber an early save
            if (editing) {
                // Resolve by server id — a concurrent delete, or the reconcile after a 404,
                // may have dropped the row. Nothing was written then, so say so rather than
                // closing the composer under a "saved" toast the reviewer would believe.
                const target = comments.find((c) => c.id === state.editId);
                if (!target)
                    throw Object.assign(new Error('HTTP 404'), { status: 404 });
                const updated = await api(
                    'PATCH',
                    `/api/v1/live-review-widget/comments/${target.id}`,
                    { body },
                );
                target.body = body;
                savedLabel = (updated && updated.label) || null;
            } else {
                // Read after the boot load, which may have refused the stored card.
                const destination = activeTarget();
                if (!destination) {
                    throw new Error('Choose where your notes go first.');
                }
                const kept = composeAnchors().filter((entry) => entry.selector);
                // The quote fields go over the wire only when the anchor names a run of
                // text; an element anchor sends none, and the API stores null.
                const anchors = kept.map((entry) => ({
                    selector: entry.selector,
                    text: entry.text,
                    ...(entry.quote
                        ? {
                              quote: entry.quote,
                              quotePrefix: entry.quotePrefix,
                              quoteSuffix: entry.quoteSuffix,
                          }
                        : {}),
                }));
                // The space is decided here rather than while drawing, because a pick
                // after the drag changes which box the fractions are measured against.
                // A quoted anchor measures against the element that holds the run.
                const box = strokeBoxOf(kept);
                const strokes = state.strokes.map((points) =>
                    storeStroke(points, box),
                );
                // selector/text repeat the first anchor for an instance that predates
                // anchors[]. This script's URL carries no version, so a browser can hold
                // this copy long after a rollback, and that instance would otherwise save
                // every comment as an unanchored note. The current API prefers anchors[].
                const first = anchors[0];
                const target =
                    destination.mode === 'per-review'
                        ? { cardId: destination.cardId }
                        : {
                              newCard:
                                  destination.mode === 'epic'
                                      ? { parentCardId: destination.cardId }
                                      : {},
                          };
                // A retry keeps its delivery id only while it names the same
                // target. The server refuses a known id sent to another one.
                const targetKey = JSON.stringify(target);
                if (
                    state.deliveryId === null ||
                    state.deliveryTarget !== targetKey
                ) {
                    state.deliveryId = newDeliveryId();
                    state.deliveryTarget = targetKey;
                }
                const scopeAtSave = scopeGeneration;
                const comment = {
                    deliveryId: state.deliveryId,
                    body,
                    url: location.href,
                    anchors,
                    strokes,
                    selector: first ? first.selector : '',
                    text: first ? first.text : '',
                    ...(lastSeenMarker ? { context: lastSeenMarker } : {}),
                };
                const saved = await api(
                    'POST',
                    '/api/v1/live-review-widget/feedback',
                    {
                        ...comment,
                        target,
                    },
                );
                if (scopeAtSave === scopeGeneration) {
                    comments.push({
                        id: saved.commentId,
                        ...comment,
                        link: saved.url
                            ? { label: `#${saved.number}`, url: saved.url }
                            : null,
                    });
                    // A list request still in flight answers with a snapshot
                    // from before this note, so it is asked again.
                    let stale = refreshPending;
                    // Only a per-note review lists the cards of this tab.
                    if (destination.mode === 'per-note') {
                        const sent = sessionCards.slice(0, SESSION_CARDS_SENT);
                        rememberSessionCard(saved.cardId);
                        // A card pushed out of the sent ids takes its notes
                        // out of the scope. The rows hold no card id, so the
                        // list keeps only the new note until the reload.
                        const kept = sessionCards.slice(0, SESSION_CARDS_SENT);
                        if (sent.some((id) => !kept.includes(id))) {
                            comments = comments.filter(
                                (row) => row.id === saved.commentId,
                            );
                            scopeUnread = true;
                            stale = true;
                        }
                    }
                    if (stale) refresh().then(sync);
                }
                savedLabel = saved.label || targetLabel(destination);
            }
            state.deliveryId = null;
            resetView();
            state.composeTarget = null;
            state.editId = null;
            // A saved draft ends the same way a cancelled one does. This block
            // is indented one level deeper than the other two, which is why it
            // was missed.
            pickerMode = null;
            state.draft = '';
            state.strokes = [];
            textareaNode.value = '';
            setDrawing(false);
            flashSaved(
                savedLabel
                    ? `Note ${editing ? 'updated in' : 'saved to'} ${savedLabel}`
                    : 'Note updated',
            );
        } catch (error) {
            // A rejected token is fatal; anything else keeps the composer open with the text
            // intact so nothing is lost. A 404 means the agent addressed it mid-edit, so the
            // list is stale — reconcile it.
            if (authFailed(error)) enterFatal(error);
            else {
                state.actionError = error;
                if (error && error.status === 404) await refresh();
                forgetRefusedTarget(error);
            }
        }
        state.saving = false;
        sync();
    };

    // Resolving says the reviewer is done with a comment. It leaves this list,
    // which holds the pending ones, and the owner can reopen it from its
    // card's Feedback tab. It shares `state.deleting` with delete, so the
    // footer and both rows disable together while one request is in flight.
    const resolveComment = async (index) => {
        const target = comments[index];
        if (!target || state.deleting) return;
        state.deleting = true;
        sync();
        try {
            await ready;
            await api(
                'POST',
                `/api/v1/live-review-widget/comments/${target.id}/resolve`,
            );
            // By id: the list can change while the request runs.
            comments = comments.filter((comment) => comment.id !== target.id);
            flashSaved('Marked resolved');
        } catch (error) {
            if (authFailed(error)) enterFatal(error);
            else {
                state.actionError = error;
                if (error && error.status === 404) await refresh();
            }
        }
        state.deleting = false;
        state.pinConfirmId = null;
        state.hoverId = null;
        state.hoverPinId = null;
        sync();
    };

    // ---- destructive actions (every one is confirmed) ----
    const removeComment = async (index) => {
        const target = comments[index];
        if (!target) return;
        if (state.deleting) return;
        state.deleting = true;
        sync(); // paint the disabled footer now, or the click below is silently dropped
        try {
            await ready; // don't let the boot refresh clobber an early delete
            await api(
                'DELETE',
                `/api/v1/live-review-widget/feedback/${target.id}`,
            );
            comments = comments.filter((comment) => comment.id !== target.id);
            flashSaved('Note deleted');
        } catch (error) {
            if (authFailed(error)) enterFatal(error);
            else {
                state.actionError = error;
                if (error && error.status === 404) await refresh();
            }
        }
        state.deleting = false;
        state.pinConfirmId = null;
        state.hoverId = null;
        state.hoverPinId = null;
        sync();
    };
    const confirmClearYes = async () => {
        if (state.deleting) return;
        state.deleting = true;
        try {
            await ready; // don't let the boot refresh clobber an early clear
            // A list still loading can hold notes of an old scope.
            while (refreshPending) await listReady;
            if (scopeUnread) {
                await refresh();
                while (refreshPending) await listReady;
            }
            if (scopeUnread) {
                throw new Error(
                    'Couldn’t reload your notes, so nothing was deleted. Please try again.',
                );
            }
            // allSettled, not all: a rejection from one delete must not send us to
            // refresh() while the rest are still in flight, or the rehydrate races
            // them and restores rows that are about to disappear.
            const doomed = comments.map((comment) => comment.id);
            const results = await Promise.allSettled(
                doomed.map((id) =>
                    api('DELETE', `/api/v1/live-review-widget/feedback/${id}`),
                ),
            );
            const failure = results.find(
                (result) => 'rejected' === result.status,
            );
            if (!failure) {
                comments = comments.filter(
                    (comment) => !doomed.includes(comment.id),
                );
                flashSaved(
                    doomed.length === 1
                        ? 'Note deleted'
                        : `${doomed.length} notes deleted`,
                );
            } else if (authFailed(failure.reason)) {
                enterFatal(failure.reason);
            } else {
                // Some deletes landed, and a 404 just means the agent addressed one
                // first — either way the server is the truth now.
                state.actionError = failure.reason;
                await refresh();
            }
        } catch (error) {
            if (authFailed(error)) {
                enterFatal(error);
            } else {
                state.actionError = error;
                await refresh();
            }
        }
        state.deleting = false;
        resetView();
        state.composeTarget = null;
        state.editId = null;
        state.strokes = [];
        setDrawing(false);
        state.hoverId = null;
        state.hoverPinId = null;
        state.pinConfirmId = null;
        sync();
    };

    // ---- pin hover (intent: a 160ms close delay bridges the pin→popover gap) ----
    const hoverPin = (index) => {
        clearTimeout(pinCloseTimer);
        state.hoverPinId = index;
        renderPins();
        updateHighlight();
    };
    const unhoverPin = () => {
        clearTimeout(pinCloseTimer);
        pinCloseTimer = setTimeout(() => {
            state.hoverPinId = null;
            state.pinConfirmId = null;
            renderPins();
            updateHighlight();
        }, 160);
    };

    // ---- element picker (target mode) ----
    // Mirrors the :host rule above. Pick mode does not live in the shadow roots
    // that rule hides: its click and contextmenu handlers are on the document and
    // the crosshair is a stylesheet on the root. An invisible widget eating the
    // page's clicks is worse than no widget, so picking is stood down on the way
    // in and refused while hidden — the launcher goes, the 't' shortcut does not.
    const hidden = window.matchMedia(
        '(max-width:639px),(hover:none) and (pointer:coarse)',
    );
    const setTargeting = (on) => {
        if (on && hidden.matches) return;
        // The two modes both own the pointer, so only one of them can be up.
        if (on) setDrawing(false);
        state.target = on;
        if (!on) {
            state.moveHL = null;
            state.expandLevel = 0;
            state.addAnchor = false;
            moveBase = null;
        } else {
            state.toastDock = toastHome();
        }
        cursorStyle.textContent = on ? '*{cursor:crosshair !important}' : '';
        if (on) {
            document.addEventListener('mousemove', onMove, true);
            document.addEventListener('mousedown', onDown, true);
            document.addEventListener('click', onClick, true);
            document.addEventListener('contextmenu', onContext, true);
            window.addEventListener('wheel', onWheel, { passive: false });
        } else {
            document.removeEventListener('mousemove', onMove, true);
            document.removeEventListener('mousedown', onDown, true);
            document.removeEventListener('click', onClick, true);
            document.removeEventListener('contextmenu', onContext, true);
            window.removeEventListener('wheel', onWheel);
        }
    };

    hidden.addEventListener('change', (event) => {
        if (event.matches) {
            setTargeting(false);
            setDrawing(false);
            // setTargeting only moves state; without this the widget comes back on a
            // widen still showing the pick toast it is no longer in.
            sync();
        }
    });
    const toggleTarget = () => {
        if (state.fatal) return;
        const on = !state.target;
        if (on && askModeFirst()) return;
        // Picking while a new comment is open adds to it. Discarding the draft
        // there would lose the body, the anchors already chosen and the drawing.
        const extending =
            composing() &&
            state.editId == null &&
            (composeAnchors().length > 0 ||
                state.strokes.length > 0 ||
                state.draft.trim() !== '');
        if (on && !extending) {
            resetView();
            state.composeTarget = null;
            state.editId = null;
            state.draft = '';
            state.strokes = [];
            textareaNode.value = '';
        }
        if (on) state.open = true;
        setTargeting(on);
        sync();
    };
    const onMove = (event) => {
        if (!state.target) return;
        updateToastDodge(event.clientY);
        const base = pickEl(event);
        if (!base) {
            moveBase = null;
            if (state.moveHL) {
                state.moveHL = null;
                state.expandLevel = 0;
                updateHighlight();
            }
            return;
        }
        let level = state.expandLevel;
        if (base !== moveBase) {
            moveBase = base;
            level = 0;
        }
        const current = climbUp(base, level);
        const r = rectOf(current);
        if (!r) return;
        state.expandLevel = level;
        state.moveHL = {
            left: r.left,
            top: r.top,
            width: r.width,
            height: r.height,
            label: firstLineLabel(current.innerText),
        };
        updateHighlight();
    };
    // A mousedown focuses what it lands on. During a pick that gives the host
    // page's link, input or button a focus ring and takes the caret out of the
    // composer, for a gesture that only meant to select. The click still fires.
    const onDown = (event) => {
        if (!state.target || !pickEl(event)) return;
        event.preventDefault();
    };
    const onClick = (event) => {
        if (!state.target) return;
        const base = pickEl(event);
        // A click that isn't on the host page (e.g. on the panel itself) is left alone.
        if (!base) return;
        event.preventDefault();
        event.stopPropagation();
        const current = climbUp(base, state.expandLevel);
        // The modifier read at click time is what makes a hold that started before
        // the first pick work: no composer was open, so no keydown could arm it.
        openElementComposer(current, modHeld(event) && !state.modCancelled);
    };
    // Granularity: widen to parent (delta>0) or narrow back (delta<0) relative to the
    // element under the cursor. Clamped to stay inside the host content.
    const adjustLevel = (delta) => {
        if (!state.target || !moveBase) return;
        const level = state.expandLevel;
        let next;
        if (delta > 0) {
            const parent = climbUp(moveBase, level).parentElement;
            if (
                !parent ||
                parent === document.body ||
                parent === document.documentElement ||
                insideWidget(parent)
            ) {
                return;
            }
            next = level + 1;
        } else {
            if (level === 0) return;
            next = level - 1;
        }
        const current = climbUp(moveBase, next);
        const r = rectOf(current);
        state.expandLevel = next;
        if (r) {
            state.moveHL = {
                left: r.left,
                top: r.top,
                width: r.width,
                height: r.height,
                label: firstLineLabel(current.innerText),
            };
        }
        updateHighlight();
    };
    // Alt/⌥ + wheel resizes the selection; a plain wheel still scrolls the host page,
    // so picking never traps scrolling on long pages.
    const onWheel = (event) => {
        if (!state.target || !moveBase || !event.altKey) return;
        event.preventDefault();
        adjustLevel(event.deltaY < 0 ? 1 : -1);
    };
    const onContext = (event) => {
        if (!state.target) return;
        event.preventDefault();
        setTargeting(false);
        sync();
    };

    // ---- moving the launcher ----
    const launcherNode = $('lp-launcher');
    const nextCorner = () =>
        CORNERS[(CORNERS.indexOf(state.corner) + 1) % CORNERS.length];

    const applyCorner = () => {
        const atTop = state.corner.startsWith('top');
        const atLeft = state.corner.endsWith('left');
        [launcherNode, panelNode].forEach((node) => {
            node.classList.toggle('at-top', atTop);
            node.classList.toggle('at-left', atLeft);
        });
        // Told to the host page so an embedder can move its own pinned chrome out
        // of the way. `data-loupe-review-open` is the same contract.
        document.documentElement.setAttribute(
            'data-loupe-review-corner',
            state.corner,
        );
    };
    const setCorner = (corner) => {
        if (!CORNERS.includes(corner)) return;
        if (corner !== state.corner) {
            state.corner = corner;
            writeCorner(corner);
        }
        applyCorner();
        sync();
    };

    const DRAG_THRESHOLD = 4; // px of travel before a press counts as a drag
    let drag = null;

    // A drag ends in a click on whatever the pointer was released over, and that
    // gesture meant "move", not "open". Swallowing it at the top of the capture
    // path beats a timer: the listener removes itself on the click it eats, and
    // the next press removes it too, so a drag that ends off the page leaves
    // nothing armed against an unrelated click.
    const swallowNextClick = (event) => {
        window.removeEventListener('click', swallowNextClick, true);
        event.preventDefault();
        event.stopPropagation();
    };
    const disarmClickSwallow = () =>
        window.removeEventListener('click', swallowNextClick, true);
    const armClickSwallow = () => {
        disarmClickSwallow();
        window.addEventListener('click', swallowNextClick, true);
    };

    const nearestCorner = (rect) =>
        (rect.top + rect.height / 2 < window.innerHeight / 2
            ? 'top'
            : 'bottom') +
        '-' +
        (rect.left + rect.width / 2 < window.innerWidth / 2 ? 'left' : 'right');

    const onDragMove = (event) => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        if (!drag.moved) {
            if (
                Math.abs(event.clientX - drag.startX) < DRAG_THRESHOLD &&
                Math.abs(event.clientY - drag.startY) < DRAG_THRESHOLD
            ) {
                return;
            }
            drag.moved = true;
            launcherNode.classList.add('dragging');
        }
        // Clamped to the viewport, so a launcher can never be dropped where its own
        // corner control is out of reach.
        const clamp = (value, limit) =>
            Math.min(Math.max(value, 0), Math.max(0, limit));
        launcherNode.style.right = 'auto';
        launcherNode.style.bottom = 'auto';
        launcherNode.style.left =
            clamp(
                event.clientX - drag.offsetX,
                window.innerWidth - drag.width,
            ) + 'px';
        launcherNode.style.top =
            clamp(
                event.clientY - drag.offsetY,
                window.innerHeight - drag.height,
            ) + 'px';
    };
    const stopDrag = () => {
        document.removeEventListener('pointermove', onDragMove, true);
        document.removeEventListener('pointerup', onDragEnd, true);
        document.removeEventListener('pointercancel', onDragCancel, true);
        window.removeEventListener('blur', onDragCancel);
        launcherNode.classList.remove('dragging');
        ['left', 'top', 'right', 'bottom'].forEach((side) => {
            launcherNode.style[side] = '';
        });
        drag = null;
    };
    // A pointer lost mid-drag (a window switch, a cancelled gesture) returns the
    // launcher to the corner it started from rather than leaving it loose. It
    // arms no swallow: the browser sends no click after a cancelled gesture, so
    // one armed here would sit and eat the reviewer's next click on the page.
    const onDragCancel = () => {
        if (!drag) return;
        stopDrag();
    };
    const onDragEnd = (event) => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        const moved = drag.moved;
        // Measured before stopDrag clears the inline offsets that put it there.
        const rect = launcherNode.getBoundingClientRect();
        stopDrag();
        if (!moved) return;
        armClickSwallow();
        setCorner(nearestCorner(rect));
    };

    launcherNode.addEventListener('pointerdown', (event) => {
        // Primary button only, and never while picking: the picker owns the page's
        // clicks, and the launcher is only up there to hold an open draft.
        if (0 !== event.button || state.target || drag) return;
        const rect = launcherNode.getBoundingClientRect();
        drag = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
            width: rect.width,
            height: rect.height,
            moved: false,
        };
        document.addEventListener('pointermove', onDragMove, true);
        document.addEventListener('pointerup', onDragEnd, true);
        document.addEventListener('pointercancel', onDragCancel, true);
        window.addEventListener('blur', onDragCancel);
    });
    // Any fresh press clears a swallow that somehow outlived the click it was
    // armed for, so a stale one can never reach an unrelated gesture.
    document.addEventListener('pointerdown', disarmClickSwallow, true);

    // ---- panel open/close ----
    const togglePanel = () => {
        state.open = !state.open;
        if (!state.open) {
            setTargeting(false);
            setDrawing(false);
            resetView();
            state.composeTarget = null;
            state.editId = null;
            state.draft = '';
            state.strokes = [];
            textareaNode.value = '';
        } else if (needsMode() && !state.fatal) {
            // A review with nowhere to put its notes asks where first.
            openDestination();
            return;
        }
        sync();
    };

    // ---- static bindings (persistent nodes) ----
    $('lp-launch-main').addEventListener('click', togglePanel);
    $('lp-launch-note').addEventListener('click', openNoteComposer);
    $('lp-launch-target').addEventListener('click', toggleTarget);
    $('lp-launch-draw').addEventListener('click', toggleDraw);
    // The launcher starts expanded (panel closed), so allow tooltip overflow now; it is
    // re-clipped on collapse and re-opened here once the expand transition finishes.
    launchQuick.style.overflow = 'visible';
    launchQuick.addEventListener('transitionend', (event) => {
        if (
            event.propertyName === 'max-width' &&
            !launcherNode.classList.contains('open')
        ) {
            launchQuick.style.overflow = 'visible';
        }
    });
    $('lp-close').addEventListener('click', togglePanel);
    generalBtn.addEventListener('click', toggleNote);
    targetBtns.forEach((button) =>
        button.addEventListener('click', toggleTarget),
    );
    drawBtns.forEach((button) => button.addEventListener('click', toggleDraw));
    $('lp-compose-back').addEventListener('click', cancelCompose);
    $('lp-finish-back').addEventListener('click', leaveFinish);
    $('lp-signin-close').addEventListener('click', togglePanel);
    $('lp-sign-in').addEventListener('click', signIn);
    canvasNode.addEventListener('pointerdown', beginStroke);
    canvasNode.addEventListener('pointermove', extendStroke);
    canvasNode.addEventListener('pointerup', () => endStroke(true));
    canvasNode.addEventListener('pointercancel', () => endStroke(false));
    $$('lp-draw-undo').addEventListener('click', undoStroke);
    $$('lp-draw-clear').addEventListener('click', clearStrokes);
    $$('lp-draw-done').addEventListener('click', toggleDraw);
    $('lp-cancel').addEventListener('click', cancelCompose);
    $('lp-save').addEventListener('click', saveComment);
    $$('lp-toast-esc').addEventListener('click', () => {
        setTargeting(false);
        sync();
    });
    // The remove control sits in the overlay above the page, so the click is
    // stopped here rather than falling through to the element it is drawn over.
    ovRoot.addEventListener('click', (event) => {
        const button =
            event.target.closest &&
            event.target.closest('[data-anchor-remove]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        removeComposeAnchor(Number(button.dataset.anchorRemove));
        // The reviewer types next, and the removed control is gone, so the caret
        // goes back to the draft rather than falling to the document.
        if (composing()) focusTextarea();
    });
    // A mouse press must not focus the control. It stays focusable for the
    // keyboard, and :focus-visible is what draws the ring.
    ovRoot.addEventListener('mousedown', (event) => {
        if (
            event.target.closest &&
            event.target.closest('[data-anchor-remove]')
        )
            event.preventDefault();
    });
    // Reveal the control of whichever removable box the pointer is over.
    // A box or its own remove control. Reaching for the control must not drop the
    // emphasis, because the pointer leaves the box to get there.
    const hoverAnchorOf = (target) => {
        if (!target || !target.closest) return null;
        const control = target.closest('[data-anchor-remove]');
        if (control) return Number(control.dataset.anchorRemove);
        const box = target.closest('.highlight.removable');
        const value = box && box.dataset.anchorHover;
        return value ? Number(value) : null;
    };
    ovRoot.addEventListener('mouseover', (event) =>
        setHoverAnchor(hoverAnchorOf(event.target)),
    );
    ovRoot.addEventListener('mouseout', (event) => {
        if (hoverAnchorOf(event.relatedTarget) == null) setHoverAnchor(null);
    });
    // A press must not reach the page, which would collapse the very selection
    // the button is offering to quote before the click ever fires.
    quoteBtn.addEventListener('mousedown', (event) => event.preventDefault());
    quoteBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        commentOnSelection();
    });

    // A transient mode ends by focusing the composer, and that focus collapses
    // the page selection. Many call sites focus the textarea and only the mode
    // exits promise the offer back, so an exit marks the collapse as its own
    // instead of `readSelection` trying to infer which one it was: at event time
    // every call site looks the same.
    let collapseOwed = false;
    let restoringPick = false;
    const focusKeepingPick = () => {
        const selection = document.getSelection();
        const live = !!state.quotePick && !!selection && !selection.isCollapsed;
        focusTextarea();
        // Armed only on a collapse seen here, so its selectionchange is owed and
        // the arm needs no expiry. The event can land after any number of frames.
        collapseOwed = live && selection.isCollapsed;
    };

    // A page that reviews text of its own opts out: its own selection UI owns
    // the selection there.
    const quotesOff = (node) => {
        const element = node.nodeType === 1 ? node : node.parentElement;
        return (
            element != null &&
            element.closest('[data-site-review-quotes="off"]') != null
        );
    };

    // A selection offers to become a quoted anchor only while the panel is open
    // and no saved comment is being edited: an edit sends the body alone, so
    // taking the selection would start a new comment and lose the draft.
    // selectionchange rather than mouseup, so a keyboard selection reaches it.
    const readSelection = () => {
        const selection = document.getSelection();
        // The owed collapse of the mode we just left armed this, so keep the pick
        // that mode promised back. Honoured once. A reviewer who clears their
        // selection leaves no range at all, and our own focus move leaves one.
        if (restoringPick) {
            restoringPick = false;
            if (selection && selection.isCollapsed && selection.rangeCount > 0)
                return;
        }
        // `state.target` and `state.drawing` are absent on purpose. A mode that
        // owns the pointer only hides the offer and promises it back, so an
        // event that lands during one must leave the pick alone.
        if (
            state.fatal ||
            !state.open ||
            state.editId != null ||
            hidden.matches
        ) {
            return clearQuotePick();
        }
        if (!selection || selection.isCollapsed || !selection.rangeCount)
            return clearQuotePick();
        const range = selection.getRangeAt(0);
        if (quotesOff(range.commonAncestorContainer)) return clearQuotePick();
        const anchor = anchorForRange(range.cloneRange());
        if (!anchor) return clearQuotePick();
        state.quotePick = { range: range.cloneRange(), anchor };
        renderQuoteButton();
    };
    let selectionFrame = 0;
    document.addEventListener('selectionchange', () => {
        if (collapseOwed) {
            collapseOwed = false;
            restoringPick = true;
        }
        if (selectionFrame) return;
        selectionFrame = requestAnimationFrame(() => {
            selectionFrame = 0;
            readSelection();
        });
    });
    // A browser that collapses without a selectionchange would leave the arm up
    // for the reviewer's next collapse. Their next press drops it first.
    const dropOwedCollapse = () => {
        collapseOwed = false;
    };
    window.addEventListener('pointerdown', dropOwedCollapse, true);
    window.addEventListener('keydown', dropOwedCollapse, true);

    textareaNode.addEventListener('input', (event) => {
        state.draft = event.target.value;
        saveBtn.disabled = saveDisabled();
    });
    textareaNode.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter')
            saveComment();
    });

    // True when focus is in any text-entry field, so single-key shortcuts don't hijack
    // typing. Inside our shadow the document's activeElement is the host; root.activeElement
    // is the field.
    const isTyping = () => {
        const active = document.activeElement;
        if (
            active &&
            (active.tagName === 'INPUT' ||
                active.tagName === 'TEXTAREA' ||
                active.isContentEditable)
        ) {
            return true;
        }
        const shadowActive = root.activeElement;
        return (
            !!shadowActive &&
            (shadowActive.tagName === 'INPUT' ||
                shadowActive.tagName === 'TEXTAREA')
        );
    };

    // Add-anchor mode is armed by the modifier alone, before the typing guard below,
    // because the composer's textarea holds focus while the reviewer picks. Any other
    // key spends the hold, which leaves ⌘A, ⌘C and ⌘V doing what they always did.
    document.addEventListener('keyup', (event) => {
        if (event.key === MOD_KEY) exitAddAnchor(false);
    });
    // A keyup never arrives when the hold ends outside the page, so ⌘-Tab away and
    // a window switch both stand the picker down.
    window.addEventListener('blur', () => exitAddAnchor(false));
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) exitAddAnchor(false);
    });

    document.addEventListener('keydown', (event) => {
        // Undo a stroke, not the draft. Only while drawing, and only with the caret
        // out of the textarea, so clicking back into it restores text undo.
        if (
            state.drawing &&
            !isTyping() &&
            (event.metaKey || event.ctrlKey) &&
            !event.shiftKey &&
            event.key.toLowerCase() === 'z'
        ) {
            event.preventDefault();
            undoStroke();
            return;
        }
        if (event.key === MOD_KEY) {
            if (!event.repeat) enterAddAnchor();
            return;
        }
        // Any other key spends the hold. Escape stops there rather than falling
        // through to cancelCompose, which would throw the draft away.
        if (state.addAnchor) {
            exitAddAnchor(true);
            return;
        }
        if (event.key === 'Escape') {
            if (menu) {
                closeMenu(true);
                return;
            }
            if (state.target) {
                setTargeting(false);
                sync();
                return;
            }
            // Leaving draw mode keeps the strokes and the draft, the way leaving pick
            // mode keeps the anchors already chosen.
            if (state.drawing) {
                setDrawing(false);
                sync();
                if (composing()) focusKeepingPick();
                return;
            }
            if (state.view === 'destination') {
                leaveDestination();
                sync();
                focusAfterDestination();
                return;
            }
            if (state.view === 'finish') {
                leaveFinish();
                return;
            }
            if (composing()) {
                cancelCompose();
                return;
            }
        }
        // Single-key shortcuts only while the panel is open, no modifier held, not typing —
        // and never in the fatal state, where there is nothing to compose. Nor while
        // the breakpoint hides the widget: swallowing the host page's 'c' and 't' to
        // drive UI nobody can see is worse than not being there.
        if (!state.open || state.fatal || hidden.matches) return;
        if (event.metaKey || event.ctrlKey || event.altKey || isTyping())
            return;
        if (event.key === 'c') {
            event.preventDefault();
            openNoteComposer();
        } else if (event.key === 't') {
            event.preventDefault();
            toggleTarget();
        } else if (event.key === 'd') {
            event.preventDefault();
            toggleDraw();
        }
    });

    // Pins and the highlight are recomputed from live rects on scroll/resize.
    let repositionFrame = 0;
    const scheduleReposition = () => {
        if (repositionFrame) return;
        repositionFrame = requestAnimationFrame(() => {
            repositionFrame = 0;
            renderPins();
            updateHighlight();
            renderStrokes();
            renderQuoteButton();
            // A toast resting against the bottom is placed from innerHeight, so a
            // resize moves it.
            if (state.target) positionToast();
            else if (state.savedNotice) dockToast(savedToastNode, toastHome());
            if (state.drawing) dockToast(drawToastNode, toastHome());
        });
    };
    window.addEventListener('scroll', scheduleReposition, true);
    window.addEventListener('resize', scheduleReposition);

    // SPA / same-document navigation: pins are anchored to the URL they were made on,
    // so a client-side route change must rebuild them — otherwise stale pins linger and
    // appear to "follow" onto the new page. History methods are wrapped (and popstate /
    // hashchange listened to) to catch every same-document navigation across browsers.
    const rerenderAnchors = () => {
        // The list groups notes by the page they were made on, so a new
        // location changes it too.
        updatePanel();
        renderPins();
        updateHighlight();
        renderStrokes();
        renderQuoteButton();
    };
    // Re-resolve when the page starts naming a different card. Safe to call
    // repeatedly: it does nothing until the marker actually changes.
    // A new preview token can end the signed-out state that the old one caused.
    const recheckPreviewToken = () => {
        const token = pagePreviewToken();
        if (token === lastSeenPreviewToken) return;
        lastSeenPreviewToken = token;
        if (grant || !state.fatal || !livePreviewToken()) return;
        state.fatal = null;
        resetView();
        refresh({ firstLoad: true }).then(sync);
    };
    const recheckMarker = () => {
        recheckPreviewToken();
        const marker = pageMarker();
        if (marker === lastSeenMarker) return;
        lastSeenMarker = marker;
        const scope = scopeOf();
        // Changed now, not when the answer lands. Keeping the old card would
        // attach a comment saved during the request, or after it failed, to
        // the page the reviewer has already left.
        lock = lockFor(marker);
        if (scopeOf() !== scope) {
            // The old card's notes are not part of the new scope.
            comments = [];
            scopeGeneration++;
        }
        sync();
        refresh();
    };
    let lastSeenUrl = location.href;
    const handleLocationChange = () => {
        if (location.href === lastSeenUrl) return;
        lastSeenUrl = location.href;
        recheckMarker();
        state.hoverId = null;
        state.hoverPinId = null;
        state.pinConfirmId = null;
        // A selection held from the old page names an element that is about to go,
        // and saving it would store that selector against the new URL.
        clearQuotePick();
        rerenderAnchors();
        // Under Turbo the <body> is swapped *after* the URL changes, so the new page's
        // anchors resolve to null on this tick and would never reappear until a scroll.
        // Re-run as the new DOM settles.
        requestAnimationFrame(rerenderAnchors);
        setTimeout(rerenderAnchors, 60);
        setTimeout(rerenderAnchors, 240);
        // The script tag is swapped on the same schedule, so the marker read
        // above is the old page's. These re-reads are what actually catch a
        // navigation to a page naming a different card.
        requestAnimationFrame(recheckMarker);
        setTimeout(recheckMarker, 60);
        setTimeout(recheckMarker, 240);
    };
    ['pushState', 'replaceState'].forEach((method) => {
        const original = history[method];
        history[method] = function (...args) {
            const result = original.apply(this, args);
            handleLocationChange();
            return result;
        };
    });
    window.addEventListener('popstate', handleLocationChange);
    window.addEventListener('hashchange', handleLocationChange);
    // Turbo swaps the body without a history method we wrap; re-anchor on its render
    // events too (harmless no-ops when Turbo is absent).
    ['turbo:load', 'turbo:render', 'turbo:frame-load'].forEach((evt) =>
        document.addEventListener(evt, () => {
            rerenderAnchors();
            // A Turbo navigation that bypasses the wrapped history methods
            // notifies through these events alone, so without this the widget
            // keeps saving against the previous page's card indefinitely.
            recheckMarker();
        }),
    );

    applyCorner();
    const ready = refresh({ firstLoad: true });
    sync();
    ready.then(sync);
})();
