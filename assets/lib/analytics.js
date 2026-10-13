/**
 * The page-side half of the analytics tag. The tag asks `beforeSend` before
 * every page view and event, and a null answer cancels the send.
 */

const WAITLIST_TICKET = 't';

function publicPaths() {
    const tag = document.querySelector('script[data-public-paths]');
    try {
        const paths = JSON.parse(tag?.dataset.publicPaths ?? '[]');
        return Array.isArray(paths) ? paths : [];
    } catch {
        return [];
    }
}

export function beforeSend(type, payload) {
    if (!payload?.url) {
        return null;
    }

    const url = new URL(payload.url, window.location.origin);
    if (!publicPaths().includes(url.pathname)) {
        return null;
    }

    // The one-time waitlist ticket would make each join its own page in Umami.
    if (!url.searchParams.has(WAITLIST_TICKET)) {
        return payload;
    }
    url.searchParams.delete(WAITLIST_TICKET);
    const relative = !/^[a-z][a-z0-9+.-]*:/i.test(payload.url);

    return {
        ...payload,
        url: relative ? url.pathname + url.search + url.hash : url.href,
    };
}

export function track(name, data) {
    window.umami?.track(name, data);
}

window.loupeAnalyticsBeforeSend = beforeSend;
