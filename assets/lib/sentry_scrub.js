// Mirrors App\Observability\SentryEventScrubber: a page URL can carry a
// secret, so no path or query leaves the browser. Asset URLs stay whole.
const URL_PATTERN = /\b[a-z][a-z0-9+.-]*:\/\/[^\s"'<>]+/gi;
const EMAIL_PATTERN = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi;
// A browser error can name a path, or a URL with no scheme such as //host/x.
const PATH_PATTERN = /(^|[\s"'(=])\/(?!assets\/)[^\s"'<>)]+/g;
const SELECTOR_ID = /#[^\s.#[>]+/g;
const URL_DATA_KEYS = new Set([
    'url',
    'http.url',
    'http.query',
    'http.fragment',
    'http.request.header.referer',
    'lcp.url',
    'browser.web_vital.lcp.url',
    'lcp.id',
    'browser.web_vital.lcp.id',
]);
const SELECTOR_DATA_KEYS = new Set([
    'lcp.element',
    'browser.web_vital.lcp.element',
    'browser.web_vital.inp.target',
]);
// The SDK fills these from the scope, which holds the page path.
const ROUTE_DATA_KEYS = new Set(['sentry.transaction', 'sentry.segment.name']);
const DROPPED_CRUMB_CATEGORIES = new Set([
    'fetch',
    'xhr',
    'navigation',
    'console',
]);
const SELECTOR_ATTRIBUTE = /\[[a-z-]+=".*?"\](?=\[|\s>\s|$)/g;

function origin(url) {
    try {
        const parsed = new URL(url);
        return parsed.host === ''
            ? '[url]'
            : `${parsed.protocol}//${parsed.host}`;
    } catch {
        return '[url]';
    }
}

export function redact(text) {
    return text
        .replace(URL_PATTERN, (url) => origin(url))
        .replace(PATH_PATTERN, '$1[path]')
        .replace(EMAIL_PATTERN, '[email]');
}

function scrubLocation(text) {
    try {
        const parsed = new URL(text);
        if (
            parsed.pathname.startsWith('/assets/') &&
            parsed.search === '' &&
            parsed.hash === ''
        ) {
            return text;
        }
    } catch {
        // Not a URL: redact what it holds.
    }

    return redact(text);
}

// The SDK builds a selector from tag, id, classes, then the values of
// aria-label, type, name, title and alt. Those values and a heading id, which
// the Markdown renderer derives from the text, can hold user content.
export function scrubSelector(selector) {
    const stripped = selector
        .replace(SELECTOR_ATTRIBUTE, '')
        .replace(SELECTOR_ID, '');

    return stripped.includes('"') ? '[Filtered]' : stripped;
}

function isSelectorKey(key) {
    return (
        SELECTOR_DATA_KEYS.has(key) ||
        /^(browser\.web_vital\.)?cls\.source\.\d+$/.test(key)
    );
}

function scrubData(data, route) {
    for (const [key, value] of Object.entries(data)) {
        if (ROUTE_DATA_KEYS.has(key)) {
            if (route) {
                data[key] = route;
            } else {
                delete data[key];
            }
        } else if (URL_DATA_KEYS.has(key) || key.startsWith('url.')) {
            delete data[key];
        } else if (typeof value === 'string') {
            data[key] = isSelectorKey(key)
                ? scrubSelector(value)
                : scrubLocation(value);
        }
    }
}

export function scrubSpan(span, route = null) {
    if (span.data) {
        scrubData(span.data, route);
    }
    if (typeof span.description === 'string') {
        const op = span.op ?? '';
        if (op === 'http.client') {
            span.description = span.description.split(' ')[0];
        } else if (
            op.startsWith('ui.interaction') ||
            op.startsWith('ui.webvital')
        ) {
            span.description = scrubSelector(span.description);
        } else {
            span.description = scrubLocation(span.description);
        }
    }

    return span;
}

export function keepBreadcrumb(crumb) {
    if (DROPPED_CRUMB_CATEGORIES.has(crumb.category)) {
        return null;
    }
    if (
        crumb.category?.startsWith('ui.') &&
        typeof crumb.message === 'string'
    ) {
        crumb.message = scrubSelector(crumb.message);
    }

    return crumb;
}

function scrubFrames(stacktrace) {
    for (const frame of stacktrace?.frames ?? []) {
        for (const key of ['filename', 'abs_path']) {
            if (typeof frame[key] === 'string') {
                frame[key] = scrubLocation(frame[key]);
            }
        }
    }
}

// The SDK names an error event after the page path, so the route replaces it.
export function scrubEvent(event, route = null) {
    if (route) {
        event.transaction = route;
    } else if (event.type !== 'transaction') {
        delete event.transaction;
    }
    // Loupe sets no extra data, and the SDK puts a rejected object here whole.
    delete event.extra;
    if (event.request) {
        delete event.request.url;
        delete event.request.query_string;
        delete event.request.headers?.Referer;
    }
    if (typeof event.message === 'string') {
        event.message = redact(event.message);
    }
    for (const key of ['message', 'formatted']) {
        if (typeof event.logentry?.[key] === 'string') {
            event.logentry[key] = redact(event.logentry[key]);
        }
    }
    for (const exception of event.exception?.values ?? []) {
        if (typeof exception.value === 'string') {
            exception.value = redact(exception.value);
        }
        scrubFrames(exception.stacktrace);
    }
    scrubFrames(event.stacktrace);
    for (const crumb of event.breadcrumbs ?? []) {
        if (typeof crumb.message === 'string') {
            crumb.message = redact(crumb.message);
        }
        if (crumb.data) {
            delete crumb.data.url;
            delete crumb.data.from;
            delete crumb.data.to;
        }
    }

    return event;
}

export function scrubTransaction(event, route) {
    if (event.contexts?.trace?.data) {
        scrubData(event.contexts.trace.data, route);
    }
    for (const span of event.spans ?? []) {
        scrubSpan(span, route);
    }

    return event;
}

const REPLAY_ITEM_TYPES = new Set(['replay_event', 'replay_recording']);

// rrweb copies every attribute. Only the ones that shape the layout leave the
// page, so an attribute that a later feature adds stays out of Sentry.
export const KEPT_ATTRIBUTES = new Set([
    'class',
    'type',
    'role',
    'style',
    'rel',
    'width',
    'height',
    'disabled',
    'hidden',
    'checked',
    'selected',
    'open',
    'tabindex',
    'colspan',
    'rowspan',
    'dir',
    'lang',
    'aria-hidden',
    'aria-expanded',
    'aria-selected',
    'aria-current',
    'aria-checked',
    'aria-pressed',
    'aria-disabled',
    'rr_width',
    'rr_height',
    '_cssText',
]);

// The only properties that inline CSS keeps.
export const KEPT_CSS_PROPERTIES = new Set([
    'position',
    'top',
    'right',
    'bottom',
    'left',
    'inset',
    'display',
    'width',
    'height',
    'min-width',
    'min-height',
    'max-width',
    'max-height',
    'margin',
    'padding',
    'transform',
    'transition',
    'opacity',
    'visibility',
    'overflow',
    'background',
    'font-family',
    'box-shadow',
    'border-radius',
]);

// CSS keywords, and the font and animation names of Loupe's own
// stylesheets. A test keeps the list complete against assets/styles and
// the compiled Tailwind stylesheet.
export const KEPT_CSS_WORDS = new Set([
    '-apple-system',
    '-webkit-backdrop-filter',
    '-webkit-box',
    'absolute',
    'all',
    'antialiased',
    'anywhere',
    'aria-label',
    'at',
    'auto',
    'auto-fill',
    'auto-fit',
    'backdrop-filter',
    'background-color',
    'balance',
    'baseline',
    'black',
    'blinkmacsystemfont',
    'block',
    'bold',
    'bolder',
    'border-box',
    'border-color',
    'both',
    'bottom',
    'box-shadow',
    'break-all',
    'break-word',
    'button',
    'center',
    'circle',
    'clip',
    'close-quote',
    'collapse',
    'color',
    'column',
    'consolas',
    'contain',
    'content-visibility',
    'contents',
    'currentcolor',
    'dashed',
    'data-diff-label',
    'data-label',
    'data-untitled',
    'decimal',
    'default',
    'disc',
    'display',
    'dotted',
    'ease',
    'ease-in-out',
    'ease-out',
    'ellipse',
    'ellipsis',
    'evenodd',
    'false',
    'fill',
    'filter',
    'fit-content',
    'fixed',
    'flex',
    'flex-end',
    'flex-start',
    'font-size',
    'grab',
    'grabbing',
    'grayscale',
    'grid',
    'grid-template-rows',
    'height',
    'help',
    'hidden',
    'in',
    'infinite',
    'infinity',
    'inherit',
    'initial',
    'inline',
    'inline-block',
    'inline-flex',
    'inline-grid',
    'inline-size',
    'inset',
    'inside',
    'isolate',
    'italic',
    'layout',
    'lds-dual-ring',
    'left',
    'line-through',
    'linear',
    'list-item',
    'lower-alpha',
    'lower-roman',
    'lowercase',
    'lp-appear',
    'lp-arrived',
    'lp-board-card-changed',
    'lp-dialog-backdrop-in',
    'lp-progress-slide',
    'lp-topsearch-conceal',
    'lp-topsearch-reveal',
    'mandatory',
    'max-content',
    'max-width',
    'menlo',
    'middle',
    'monaco',
    'monospace',
    'no-repeat',
    'non-scaling-stroke',
    'none',
    'normal',
    'not-allowed',
    'nowrap',
    'number',
    'oklab',
    'opacity',
    'open-quote',
    'ordinal',
    'outline-color',
    'overlay',
    'paint',
    'pan-y',
    'pointer',
    'pointer-events',
    'pre',
    'pre-line',
    'pre-wrap',
    'pretty',
    'progress',
    'relative',
    'repeat-x',
    'revert',
    'right',
    'rotate',
    'round',
    'row',
    'safe-area-inset-bottom',
    'sans-serif',
    'scale',
    'separate',
    'sfmono-regular',
    'smooth',
    'solid',
    'space-between',
    'span',
    'spin',
    'srgb',
    'start',
    'static',
    'status',
    'sticky',
    'stretch',
    'stroke',
    'swap',
    'system-ui',
    'table',
    'table-cell',
    'tabular-nums',
    'text',
    'text-bottom',
    'text-decoration-color',
    'thin',
    'title',
    'to',
    'top',
    'transform',
    'translate',
    'transparent',
    'ui-monospace',
    'ui-sans-serif',
    'underline',
    'unset',
    'upper-alpha',
    'upper-roman',
    'uppercase',
    'vertical',
    'visibility',
    'visible',
    'wait',
    'wavy',
    'wrap',
    'x',
    'zoom-in',
    'zoom-out',
]);

const CSS_UNITS =
    'px|rem|em|ex|ch|lh|rlh|%|vh|vw|vmin|vmax|dvh|dvw|svh|svw|lvh|lvw|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc|deg|grad|rad|turn|s|ms|hz|khz|dpi|dpcm|dppx|x|fr';
const CSS_NUMBER = new RegExp(
    `^[+-]?(?:\\d+\\.?\\d*|\\.\\d+)(?:e[+-]?\\d+)?(?:${CSS_UNITS})?$`,
    'i',
);
const CSS_HEX_COLOR = /^#[0-9a-f]{3,8}$/i;
const CSS_UNICODE_RANGE = /^u\+[0-9a-f?]{1,6}(?:-[0-9a-f]{1,6})?$/i;
const CSS_VARIABLE = /^--[\w-]+$/;
const CSS_FUNCTION = /^[a-z_-][\w-]*\($/i;
const CSS_PROPERTY_NAME = /^(?:--[^\s:;{}()]+|-?[a-z_][\w-]*)$/i;
const CSS_IMPORTANT = /\s*!\s*important\s*$/i;

function isKeptCssToken(token) {
    return (
        CSS_NUMBER.test(token) ||
        CSS_HEX_COLOR.test(token) ||
        CSS_UNICODE_RANGE.test(token) ||
        CSS_VARIABLE.test(token) ||
        CSS_FUNCTION.test(token) ||
        token === '+' ||
        token === '-' ||
        KEPT_CSS_WORDS.has(token.toLowerCase())
    );
}

// The value has its strings emptied and its urls cut by now.
function isKeptCssValue(value) {
    const words = value
        .replace(/url\("(?:[^"\\]|\\.)*"\)/g, ' ')
        .replace(/""/g, ' ')
        .match(/[^\s,()/*]+\(?/g);

    return (words ?? []).every(isKeptCssToken);
}

function scrubCssDeclaration(name, value, builtStylesheet) {
    if (!CSS_PROPERTY_NAME.test(name)) {
        return null;
    }
    const custom = name.startsWith('--');
    if (
        !builtStylesheet &&
        (custom || !KEPT_CSS_PROPERTIES.has(name.toLowerCase()))
    ) {
        return null;
    }
    const body = value.replace(CSS_IMPORTANT, '').replace(/\s+/g, ' ').trim();
    if (body === '') {
        return null;
    }
    if (!isKeptCssValue(body)) {
        return custom ? 'initial' : null;
    }

    return CSS_IMPORTANT.test(value) ? `${body} !important` : body;
}

function scrubCssStatement(text, builtStylesheet) {
    if (text.startsWith('@')) {
        return builtStylesheet ? `${text.replace(/\s+/g, ' ')};` : null;
    }
    const colon = text.indexOf(':');
    if (colon === -1) {
        return null;
    }
    const name = text.slice(0, colon).trim();
    const value = scrubCssDeclaration(
        name,
        text.slice(colon + 1),
        builtStylesheet,
    );

    return value === null ? null : `${name}: ${value};`;
}

function endOfCssString(css, start) {
    let index = start + 1;
    while (index < css.length) {
        if (css[index] === '\\') {
            index += 2;
        } else if (css[index] === css[start]) {
            return index + 1;
        } else {
            index += 1;
        }
    }

    return css.length;
}

function cssUrl(css, start) {
    let index = start + 4;
    while (/\s/.test(css[index] ?? '')) {
        index += 1;
    }
    let target;
    if (css[index] === '"' || css[index] === "'") {
        const end = endOfCssString(css, index);
        target = css.slice(index + 1, end - 1);
        index = end;
    } else {
        const end = css.indexOf(')', index);
        target = css.slice(index, end === -1 ? css.length : end);
        index = end === -1 ? css.length : end;
    }
    const close = css.indexOf(')', index);
    let kept = '';
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(target.trim())) {
        kept = origin(target.trim());
    } else if (target.trim() !== '' && !/^data:/i.test(target.trim())) {
        kept = '[path]';
    }

    return {
        text: `url("${kept}")`,
        next: close === -1 ? css.length : close + 1,
    };
}

// Cleans CSS text. A comment goes, a string is emptied and a url keeps its
// origin. A declaration stays when each word of its value is a known CSS word.
// Inline CSS keeps only the properties of KEPT_CSS_PROPERTIES, because a
// number or a colour can hold an id. A built stylesheet holds no user data,
// so it keeps its custom properties and numbers.
export function scrubCss(css, { builtStylesheet = false } = {}) {
    const parts = [];
    let buffer = '';
    let skipped = 0;
    const endStatement = () => {
        const text = buffer.trim();
        buffer = '';
        const statement =
            skipped === 0 && text !== ''
                ? scrubCssStatement(text, builtStylesheet)
                : null;
        if (statement !== null) {
            parts.push(statement);
        }
    };

    let index = 0;
    while (index < css.length) {
        const char = css[index];
        if (char === '/' && css[index + 1] === '*') {
            const end = css.indexOf('*/', index + 2);
            buffer += ' ';
            index = end === -1 ? css.length : end + 2;
        } else if (char === '"' || char === "'") {
            buffer += '""';
            index = endOfCssString(css, index);
        } else if (
            css.slice(index, index + 4).toLowerCase() === 'url(' &&
            !/[\w-]$/.test(buffer)
        ) {
            const { text, next } = cssUrl(css, index);
            buffer += text;
            index = next;
        } else if (char === '\\') {
            buffer += css.slice(index, index + 2);
            index += 2;
        } else if (char === ';') {
            endStatement();
            index += 1;
        } else if (char === '{') {
            const prelude = buffer.trim().replace(/\s+/g, ' ');
            buffer = '';
            if (skipped > 0 || prelude.startsWith('--')) {
                skipped += 1;
            } else {
                parts.push(prelude === '' ? '{' : `${prelude} {`);
            }
            index += 1;
        } else if (char === '}') {
            endStatement();
            if (skipped > 0) {
                skipped -= 1;
            } else {
                parts.push('}');
            }
            index += 1;
        } else {
            buffer += char;
            index += 1;
        }
    }
    endStatement();

    return parts.join(' ');
}

function scrubStyleObject(style) {
    const kept = {};
    for (const [property, value] of Object.entries(style)) {
        if (typeof value === 'string') {
            const scrubbed = scrubCssValue(property, value);
            if (scrubbed !== null) {
                kept[property] = scrubbed;
            }
        } else if (Array.isArray(value) && typeof value[0] === 'string') {
            const scrubbed = scrubCssValue(property, value[0]);
            if (scrubbed !== null) {
                kept[property] = [scrubbed, value[1]];
            }
        } else if (value === false) {
            kept[property] = value;
        }
    }

    return kept;
}

function scrubCssValue(property, value) {
    if (/[;{}]/.test(value)) {
        return null;
    }
    const css = scrubCss(`${property}: ${value}`);

    return css === '' ? null : css.slice(css.indexOf(':') + 2, -1);
}

function scrubAttributes(attributes, tagName) {
    const kept = {};
    for (const [name, value] of Object.entries(attributes)) {
        if (!KEPT_ATTRIBUTES.has(name)) {
            continue;
        }
        if (name === 'style' && value !== null && typeof value === 'object') {
            kept[name] = scrubStyleObject(value);
        } else if (typeof value === 'object' && value !== null) {
            continue;
        } else if (typeof value !== 'string') {
            kept[name] = value;
        } else if (name === 'style') {
            kept[name] = scrubCss(value);
        } else if (name === '_cssText') {
            kept[name] = scrubCss(value, {
                builtStylesheet: tagName === 'link',
            });
        } else {
            kept[name] = redact(value);
        }
    }

    return kept;
}

const CSS_RECORD_KEYS = new Set(['rule', 'replace', 'replaceSync']);
const MASKED_TEXT = /^[*\s]*$/;
const DROPPED = Symbol('dropped');

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function scrubRecordValue(value, key = '', parent = {}) {
    if (typeof value === 'string') {
        if (
            CSS_RECORD_KEYS.has(key) ||
            (key === 'textContent' && parent.isStyle)
        ) {
            return scrubCss(value);
        }
        return redact(value);
    }
    if (Array.isArray(value)) {
        return value
            .map((item) => scrubRecordValue(item, key, parent))
            .filter((item) => item !== DROPPED);
    }
    if (!isPlainObject(value)) {
        return value;
    }
    const scrubbed = {};
    for (const [name, item] of Object.entries(value)) {
        if (name === 'attributes' && isPlainObject(item)) {
            scrubbed[name] = scrubAttributes(item, value.tagName);
        } else if (name === 'texts' && Array.isArray(item)) {
            scrubbed[name] = item.map((text) => ({
                ...text,
                value:
                    typeof text.value === 'string' &&
                    !MASKED_TEXT.test(text.value)
                        ? scrubCss(text.value)
                        : redact(String(text.value ?? '')),
            }));
        } else {
            scrubbed[name] = scrubRecordValue(item, name, value);
        }
    }

    return scrubbed;
}

function scrubRecord(record) {
    const data = record?.data;
    if (record?.type === 5 && data?.tag === 'breadcrumb') {
        if (keepBreadcrumb(data.payload ?? {}) === null) {
            return DROPPED;
        }
    }
    if (record?.type === 3 && data?.source === 13 && data.set) {
        const value = scrubCssValue(
            String(data.set.property),
            String(data.set.value),
        );
        if (value === null) {
            return DROPPED;
        }
        const { set, ...rest } = data;
        const scrubbed = scrubRecordValue({ ...record, data: rest });
        scrubbed.data.set = { ...set, value };

        return scrubbed;
    }

    return scrubRecordValue(record);
}

function scrubRecording(header, payload) {
    if (typeof payload !== 'string') {
        throw new Error('A compressed replay recording cannot be scrubbed');
    }
    const newline = payload.indexOf('\n');
    const segment = JSON.parse(payload.slice(0, newline));
    const records = JSON.parse(payload.slice(newline + 1));
    const scrubbed = [
        JSON.stringify({ segment_id: Number(segment.segment_id) }),
        JSON.stringify(
            records.map(scrubRecord).filter((record) => record !== DROPPED),
        ),
    ].join('\n');

    return [
        { ...header, length: new TextEncoder().encode(scrubbed).length },
        scrubbed,
    ];
}

function redactStrings(value) {
    if (typeof value === 'string') {
        return redact(value);
    }
    if (Array.isArray(value)) {
        return value.map(redactStrings);
    }
    if (isPlainObject(value)) {
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [
                key,
                redactStrings(item),
            ]),
        );
    }

    return value;
}

// The replay envelope holds the replay event and the recording of the page.
// Both carry URLs and page content that beforeSend never sees.
export function scrubReplayEnvelope(envelope) {
    const [headers, items] = envelope;

    return [
        headers,
        items.map((item) => {
            const [header, payload] = item;
            if (header.type === 'replay_event') {
                return [header, redactStrings(scrubEvent(payload))];
            }
            if (header.type === 'replay_recording') {
                return scrubRecording(header, payload);
            }

            return item;
        }),
    ];
}

// The replay calls transport.send directly, so no client hook sees it. A
// replay item that cannot be scrubbed is dropped and never sent as it is.
export function scrubbingTransport(transport) {
    return {
        send(envelope) {
            const [headers, items] = envelope;
            if (!items.some(([header]) => REPLAY_ITEM_TYPES.has(header.type))) {
                return transport.send(envelope);
            }
            let scrubbed;
            try {
                scrubbed = scrubReplayEnvelope(envelope);
            } catch {
                const rest = items.filter(
                    ([header]) => !REPLAY_ITEM_TYPES.has(header.type),
                );
                scrubbed = rest.length === 0 ? null : [headers, rest];
            }

            return scrubbed === null
                ? Promise.resolve({})
                : transport.send(scrubbed);
        },
        flush: (timeout) => transport.flush(timeout),
    };
}
