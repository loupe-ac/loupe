import { describe, expect, it, vi } from 'vitest';
import {
    base64UrlDecode,
    base64UrlEncode,
    blockedPlatform,
    chooseContentEncoding,
    detectState,
    isIosDevice,
    unsubscribeThisBrowser,
} from '../../assets/lib/push_device.js';

const READY = {
    isIos: false,
    standalone: false,
    hasServiceWorker: true,
    hasPushManager: true,
    hasNotification: true,
    permission: 'default',
    subscribed: false,
    deviceKnown: false,
};

describe('base64UrlEncode', () => {
    it('encodes bytes with the url alphabet and no padding', () => {
        const bytes = new Uint8Array([251, 255, 254, 1]);
        expect(base64UrlEncode(bytes.buffer)).toBe('-__-AQ');
    });

    it('accepts a typed array view', () => {
        expect(base64UrlEncode(new Uint8Array([104, 105]))).toBe('aGk');
    });

    it('returns an empty string for an empty buffer', () => {
        expect(base64UrlEncode(new ArrayBuffer(0))).toBe('');
    });
});

describe('base64UrlDecode', () => {
    it('round-trips with the encoder', () => {
        const bytes = new Uint8Array([4, 250, 251, 252, 0, 7]);
        expect(Array.from(base64UrlDecode(base64UrlEncode(bytes)))).toEqual(
            Array.from(bytes),
        );
    });

    it('adds the padding that the url form leaves out', () => {
        expect(Array.from(base64UrlDecode('aGk'))).toEqual([104, 105]);
    });
});

describe('detectState', () => {
    it('is off when the browser can push and has nothing yet', () => {
        expect(detectState(READY)).toBe('off');
    });

    it('is on when the browser is subscribed and a device row matches', () => {
        expect(
            detectState({ ...READY, subscribed: true, deviceKnown: true }),
        ).toBe('on');
    });

    it('is off when the browser is subscribed but no device row matches', () => {
        expect(detectState({ ...READY, subscribed: true })).toBe('off');
    });

    it('is off when a device row exists but the browser has no subscription', () => {
        expect(detectState({ ...READY, deviceKnown: true })).toBe('off');
    });

    it('is off when the device stopped working although the browser holds a subscription', () => {
        expect(
            detectState({
                ...READY,
                subscribed: true,
                deviceKnown: true,
                deviceStopped: true,
            }),
        ).toBe('off');
    });

    it('is blocked when the permission is denied', () => {
        expect(detectState({ ...READY, permission: 'denied' })).toBe('blocked');
    });

    it('is not supported when the service worker is missing', () => {
        expect(detectState({ ...READY, hasServiceWorker: false })).toBe(
            'not-supported',
        );
    });

    it('is not supported when the push manager is missing', () => {
        expect(detectState({ ...READY, hasPushManager: false })).toBe(
            'not-supported',
        );
    });

    it('is not supported when notifications are missing', () => {
        expect(detectState({ ...READY, hasNotification: false })).toBe(
            'not-supported',
        );
    });

    it('is ios-tab on iOS outside the Home Screen app, even without a push manager', () => {
        expect(
            detectState({
                ...READY,
                isIos: true,
                hasPushManager: false,
                hasNotification: false,
            }),
        ).toBe('ios-tab');
    });

    it('treats the iOS Home Screen app as a normal browser', () => {
        expect(detectState({ ...READY, isIos: true, standalone: true })).toBe(
            'off',
        );
    });
});

describe('isIosDevice', () => {
    it('knows an iPhone', () => {
        expect(isIosDevice('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', 5)).toBe(
            true,
        );
    });

    it('knows an iPad that reports itself as a Mac', () => {
        expect(isIosDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X)', 5)).toBe(
            true,
        );
    });

    it('does not take a real Mac for an iPad', () => {
        expect(isIosDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X)', 0)).toBe(
            false,
        );
    });

    it('does not take Android for iOS', () => {
        expect(isIosDevice('Mozilla/5.0 (Linux; Android 14)', 5)).toBe(false);
    });
});

describe('blockedPlatform', () => {
    it.each([
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1', 5, 'ios'],
        [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Firefox/130.0',
            0,
            'firefox',
        ],
        [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/130.0 Safari/537.36',
            0,
            'chromium',
        ],
        [
            'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36 Edg/130.0',
            0,
            'chromium',
        ],
        [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Version/17.0 Safari/605.1.15',
            0,
            'safari',
        ],
        ['Lynx/2.9', 0, 'other'],
    ])('reads %s as %s', (userAgent, touchPoints, expected) => {
        expect(blockedPlatform(userAgent, touchPoints)).toBe(expected);
    });
});

describe('chooseContentEncoding', () => {
    it('prefers aes128gcm', () => {
        expect(chooseContentEncoding(['aesgcm', 'aes128gcm'])).toBe(
            'aes128gcm',
        );
    });

    it('falls back to the first the browser supports', () => {
        expect(chooseContentEncoding(['aesgcm'])).toBe('aesgcm');
    });

    it('assumes aes128gcm when the browser does not say', () => {
        expect(chooseContentEncoding(undefined)).toBe('aes128gcm');
        expect(chooseContentEncoding([])).toBe('aes128gcm');
    });
});

describe('unsubscribeThisBrowser', () => {
    it('unsubscribes the subscription of the registration', async () => {
        const unsubscribe = vi.fn().mockResolvedValue(true);
        const environment = {
            serviceWorker: {
                getRegistration: vi.fn().mockResolvedValue({
                    pushManager: {
                        getSubscription: vi
                            .fn()
                            .mockResolvedValue({ unsubscribe }),
                    },
                }),
            },
        };

        await unsubscribeThisBrowser(environment);

        expect(unsubscribe).toHaveBeenCalledTimes(1);
    });

    it('does nothing without a service worker', async () => {
        await expect(unsubscribeThisBrowser({})).resolves.toBeUndefined();
    });

    it('does nothing without a registration', async () => {
        const environment = {
            serviceWorker: {
                getRegistration: vi.fn().mockResolvedValue(undefined),
            },
        };
        await expect(
            unsubscribeThisBrowser(environment),
        ).resolves.toBeUndefined();
    });

    it('does nothing without a subscription', async () => {
        const environment = {
            serviceWorker: {
                getRegistration: vi.fn().mockResolvedValue({
                    pushManager: {
                        getSubscription: vi.fn().mockResolvedValue(null),
                    },
                }),
            },
        };
        await expect(
            unsubscribeThisBrowser(environment),
        ).resolves.toBeUndefined();
    });

    it('swallows a failure', async () => {
        const environment = {
            serviceWorker: {
                getRegistration: vi.fn().mockRejectedValue(new Error('boom')),
            },
        };
        await expect(
            unsubscribeThisBrowser(environment),
        ).resolves.toBeUndefined();
    });
});
