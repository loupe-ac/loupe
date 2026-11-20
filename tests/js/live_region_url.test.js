/** @vitest-environment jsdom */
import { StreamActions } from '@hotwired/turbo';
import { describe, expect, it } from 'vitest';
import { dropLiveParam } from '../../assets/lib/live_region_url.js';

function region(url) {
    const element = document.createElement('div');
    element.dataset.liveRegionUrlValue = url;

    return element;
}

describe('live region url', () => {
    it('drops the named parameter and keeps the others', () => {
        const element = region('/p/metrics?metric=a&graph=g1&range=7d');
        dropLiveParam(element, 'graph');
        expect(element.dataset.liveRegionUrlValue).toBe(
            '/p/metrics?metric=a&range=7d',
        );
    });

    it('leaves an address with no such parameter as it is', () => {
        const element = region('/p/metrics?metric=a');
        dropLiveParam(element, 'graph');
        expect(element.dataset.liveRegionUrlValue).toBe('/p/metrics?metric=a');
    });

    it('registers the stream action', () => {
        expect(typeof StreamActions['live-region-drop-param']).toBe('function');
    });
});
