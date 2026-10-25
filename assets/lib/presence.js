// Pure helpers for the presence heartbeat. No DOM access, so a test can drive
// each one with plain values.

export const POST_INTERVAL_MILLISECONDS = 30000;
export const ACTIVE_WINDOW_MILLISECONDS = 60000;

// A timer can fire a little early, so the guard sits below the interval.
const MINIMUM_GAP_MILLISECONDS = 25000;

export const ACTIVITY_EVENTS = [
    'keydown',
    'pointerdown',
    'pointermove',
    'scroll',
];

/** The page is in use: visible, focused, and the user did something in the last minute. */
export function isActive({ visible, focused, lastActivityAt, now }) {
    return (
        visible && focused && now - lastActivityAt <= ACTIVE_WINDOW_MILLISECONDS
    );
}

/** A heartbeat goes out when the page is in use and the last one is old enough. */
export function shouldPost({ active, lastPostAt, now }) {
    return active && now - lastPostAt >= MINIMUM_GAP_MILLISECONDS;
}

/** The shown notifications that no open push stands behind. */
export function staleNotifications(shown, openTags) {
    return shown.filter((notification) => !openTags.includes(notification.tag));
}

/** Closes the stale notifications of this browser. A browser with no registration shows none. */
export async function closeStaleNotifications(registration, openTags) {
    if (!registration || !Array.isArray(openTags)) {
        return 0;
    }
    const stale = staleNotifications(
        await registration.getNotifications(),
        openTags,
    );
    stale.forEach((notification) => notification.close());
    return stale.length;
}
