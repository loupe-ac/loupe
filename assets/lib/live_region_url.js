import { StreamActions } from '@hotwired/turbo';

/**
 * Removes one query parameter from the address that a live region reads.
 * The stream names the region as its target and the parameter in `param`.
 */
export function dropLiveParam(element, param) {
    if (!element?.dataset.liveRegionUrlValue || !param) {
        return;
    }
    const url = new URL(
        element.dataset.liveRegionUrlValue,
        window.location.href,
    );
    url.searchParams.delete(param);
    element.dataset.liveRegionUrlValue = `${url.pathname}${url.search}${url.hash}`;
}

StreamActions['live-region-drop-param'] = function liveRegionDropParam() {
    this.targetElements.forEach((element) =>
        dropLiveParam(element, this.getAttribute('param')),
    );
};
