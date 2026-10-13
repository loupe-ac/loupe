/**
 * The page-side half of the analytics tag. The tag asks `beforeSend` before
 * every page view and event, and a null answer cancels the send.
 */

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

    const path = new URL(payload.url, window.location.origin).pathname;

    return publicPaths().includes(path) ? payload : null;
}

export function track(name, data) {
    window.umami?.track(name, data);
}

window.loupeAnalyticsBeforeSend = beforeSend;
