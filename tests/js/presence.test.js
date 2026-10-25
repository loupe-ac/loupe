import { describe, expect, it, vi } from 'vitest';
import {
    ACTIVE_WINDOW_MILLISECONDS,
    closeStaleNotifications,
    isActive,
    shouldPost,
    staleNotifications,
} from '../../assets/lib/presence.js';

const NOW = 1_000_000;

describe('isActive', () => {
    const base = {
        visible: true,
        focused: true,
        lastActivityAt: NOW,
        now: NOW,
    };

    it('is true for a visible, focused page with fresh input', () => {
        expect(isActive(base)).toBe(true);
    });

    it('is true when the last input is exactly 60 seconds old', () => {
        expect(
            isActive({ ...base, now: NOW + ACTIVE_WINDOW_MILLISECONDS }),
        ).toBe(true);
    });

    it('is false when the last input is 61 seconds old', () => {
        expect(
            isActive({ ...base, now: NOW + ACTIVE_WINDOW_MILLISECONDS + 1000 }),
        ).toBe(false);
    });

    it('is false for a hidden page', () => {
        expect(isActive({ ...base, visible: false })).toBe(false);
    });

    it('is false for a page without focus', () => {
        expect(isActive({ ...base, focused: false })).toBe(false);
    });
});

describe('shouldPost', () => {
    it('posts at once when nothing was posted yet', () => {
        expect(shouldPost({ active: true, lastPostAt: 0, now: NOW })).toBe(
            true,
        );
    });

    it('waits when the last post is recent', () => {
        expect(
            shouldPost({ active: true, lastPostAt: NOW - 10000, now: NOW }),
        ).toBe(false);
    });

    it('posts when the last post is 30 seconds old', () => {
        expect(
            shouldPost({ active: true, lastPostAt: NOW - 30000, now: NOW }),
        ).toBe(true);
    });

    it('never posts for an inactive page', () => {
        expect(shouldPost({ active: false, lastPostAt: 0, now: NOW })).toBe(
            false,
        );
    });
});

describe('staleNotifications', () => {
    it('keeps the notifications whose tag is not open', () => {
        const shown = [{ tag: 'ask-1' }, { tag: 'ask-2' }, { tag: 'item-3' }];

        expect(staleNotifications(shown, ['ask-2'])).toEqual([
            { tag: 'ask-1' },
            { tag: 'item-3' },
        ]);
    });
});

describe('closeStaleNotifications', () => {
    const notification = (tag) => ({ tag, close: vi.fn() });

    it('closes only the notifications that are not open', async () => {
        const open = notification('ask-1');
        const stale = notification('ask-2');
        const registration = {
            getNotifications: vi.fn().mockResolvedValue([open, stale]),
        };

        const closed = await closeStaleNotifications(registration, ['ask-1']);

        expect(closed).toBe(1);
        expect(open.close).not.toHaveBeenCalled();
        expect(stale.close).toHaveBeenCalledTimes(1);
    });

    it('does nothing without a registration', async () => {
        expect(await closeStaleNotifications(undefined, ['ask-1'])).toBe(0);
    });

    it('closes nothing when the list is not an array', async () => {
        const stale = notification('ask-2');
        const registration = {
            getNotifications: vi.fn().mockResolvedValue([stale]),
        };

        expect(await closeStaleNotifications(registration, undefined)).toBe(0);
        expect(stale.close).not.toHaveBeenCalled();
    });
});
