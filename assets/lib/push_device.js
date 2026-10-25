// Pure helpers for the web push device controls. No DOM access, so a test
// can drive each one with plain values.

export function base64UrlEncode(buffer) {
    const bytes =
        buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : buffer;
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }
    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

export function base64UrlDecode(value) {
    const padded = value
        .replace(/-/g, '+')
        .replace(/_/g, '/')
        .padEnd(Math.ceil(value.length / 4) * 4, '=');
    return Uint8Array.from(atob(padded), (character) =>
        character.charCodeAt(0),
    );
}

/** iPadOS reports a Mac user agent, so a Mac with a touch screen is an iPad. */
export function isIosDevice(userAgent, maxTouchPoints) {
    if (/iPhone|iPad|iPod/.test(userAgent)) {
        return true;
    }
    return /Macintosh/.test(userAgent) && maxTouchPoints > 1;
}

/**
 * The state of push for this browser: ios-tab, not-supported, blocked, on or off.
 * An iOS tab has no push manager, so it is judged before support.
 */
export function detectState({
    isIos,
    standalone,
    hasServiceWorker,
    hasPushManager,
    hasNotification,
    permission,
    subscribed,
    deviceKnown,
}) {
    if (isIos && !standalone) {
        return 'ios-tab';
    }
    if (!hasServiceWorker || !hasPushManager || !hasNotification) {
        return 'not-supported';
    }
    if (permission === 'denied') {
        return 'blocked';
    }
    return subscribed && deviceKnown ? 'on' : 'off';
}

/** Which set of steps undoes a block: ios, firefox, chromium, safari or other. */
export function blockedPlatform(userAgent, maxTouchPoints) {
    if (isIosDevice(userAgent, maxTouchPoints)) {
        return 'ios';
    }
    if (/Firefox|FxiOS/.test(userAgent)) {
        return 'firefox';
    }
    if (/Chrome|Chromium|CriOS|Edg\//.test(userAgent)) {
        return 'chromium';
    }
    if (/Safari/.test(userAgent)) {
        return 'safari';
    }
    return 'other';
}

export function chooseContentEncoding(supported) {
    if (!Array.isArray(supported) || supported.length === 0) {
        return 'aes128gcm';
    }
    return supported.includes('aes128gcm') ? 'aes128gcm' : supported[0];
}

/** Never throws: sign-out must go on when the browser has no subscription or refuses. */
export async function unsubscribeThisBrowser(environment = navigator) {
    try {
        const registration = await environment.serviceWorker?.getRegistration();
        const subscription = await registration?.pushManager?.getSubscription();
        await subscription?.unsubscribe();
    } catch {
        // The server drops the device row on sign-out whatever the browser does.
    }
}
