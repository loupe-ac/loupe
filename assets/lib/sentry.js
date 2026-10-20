import {
    keepBreadcrumb,
    scrubEvent,
    scrubSpan,
    scrubTransaction,
    scrubbingTransport,
} from './sentry_scrub.js';
import { enableSpans } from './sentry_spans.js';

function meta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.content ?? null;
}

// beforeStartSpan names a page load after its route, and a Turbo visit can
// change the route before the transaction ends. Any name that is not a route
// or an op, such as one that holds a path, takes the current route instead.
function transactionName(name, route) {
    return typeof name === 'string' && /^[a-z0-9_.]+$/i.test(name)
        ? name
        : route;
}

function rate(name) {
    const value = Number(meta(name));

    return Number.isFinite(value) && value > 0 ? value : 0;
}

// The SDK loads from a classic deferred script, which runs before the module
// scripts that follow it, so it runs before this module.
export function initSentry(Sentry = window.Sentry) {
    const dsn = meta('sentry-browser-dsn');
    if (!Sentry || !dsn) {
        return false;
    }
    // Turbo swaps the head meta tags on each visit, so read the route late.
    const route = () => meta('loupe-route') || null;
    const sessionRate = rate('sentry-browser-replays-session-sample-rate');
    const errorRate = rate('sentry-browser-replays-on-error-sample-rate');
    // The replay code is a second file, which the page loads only when a rate is set.
    const replay =
        (sessionRate > 0 || errorRate > 0) &&
        typeof Sentry.replayIntegration === 'function';

    Sentry.init({
        dsn,
        release: meta('sentry-release') ?? undefined,
        environment: meta('sentry-environment'),
        tracesSampleRate: Number(meta('sentry-browser-traces-sample-rate')),
        // The default 'stream' lifecycle ignores beforeSendTransaction.
        traceLifecycle: 'static',
        // These replace sendDefaultPii, which SDK 11 no longer reads. Headers
        // stay on for User-Agent; scrubEvent drops the Referer.
        dataCollection: {
            userInfo: false,
            cookies: false,
            urlQueryParams: false,
        },
        integrations: [
            Sentry.browserTracingIntegration({
                instrumentNavigation: false,
                beforeStartSpan: (context) => ({
                    ...context,
                    name: route() ?? context.op,
                }),
            }),
            Sentry.breadcrumbsIntegration({ console: false }),
            ...(replay
                ? [
                      Sentry.replayIntegration({
                          maskAllText: true,
                          maskAllInputs: true,
                          blockAllMedia: true,
                          // A compressed recording cannot be scrubbed.
                          useCompression: false,
                      }),
                  ]
                : []),
        ],
        ...(replay && {
            replaysSessionSampleRate: sessionRate,
            replaysOnErrorSampleRate: errorRate,
        }),
        // The replay calls transport.send directly, which no client hook sees.
        transport: (options) =>
            scrubbingTransport(Sentry.makeFetchTransport(options)),
        beforeSend: (event) => scrubEvent(event, route()),
        beforeSendTransaction: (event) => {
            const name =
                transactionName(event.transaction, route()) ??
                event.contexts?.trace?.op ??
                'transaction';

            return scrubTransaction(scrubEvent(event, name), name);
        },
        beforeSendSpan: Sentry.withStaticSpan((span) =>
            scrubSpan(span, route()),
        ),
        beforeBreadcrumb: keepBreadcrumb,
    });
    // The page load wrote the path into the scope during init.
    const nameScope = () =>
        Sentry.getCurrentScope().setTransactionName(route() ?? undefined);
    nameScope();
    document.addEventListener('turbo:load', nameScope);
    enableSpans(Sentry);

    return true;
}

initSentry();
