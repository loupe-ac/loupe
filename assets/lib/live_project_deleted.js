import { on } from './live.js';
import { LiveFetcher, liveRequest, showStoppedBand } from './live_fetch.js';

let remove;
let fetcher;

function showStopped() {
    const region =
        document.querySelector('[data-live-content]') ??
        document.getElementById('main-content') ??
        document.body;
    showStoppedBand(region);
}

/**
 * Listens once for the deletion of the project of the page. The page names its
 * project in a [data-live-project] attribute. On a signal for that project it
 * reads the current page, and the answer shows the lost-access band. A failed
 * read retries, and the last failure shows the stopped band.
 *
 * @returns {() => void} removes the handler
 */
export function registerProjectDeleted() {
    remove ??= on('project.deleted', (change) => {
        const marker = document.querySelector('[data-live-project]');
        if (
            change.own ||
            !marker ||
            marker.dataset.liveProject !== String(change.projectId)
        ) {
            return;
        }
        if (!fetcher || fetcher.stopped) {
            fetcher = new LiveFetcher({
                root: marker,
                load: (signal) => liveRequest(window.location.href, { signal }),
                apply: () => {},
                onStopped: showStopped,
                hold: () => false,
            });
        }
        fetcher.request();
    });

    return () => {
        remove?.();
        remove = undefined;
        fetcher?.stop();
        fetcher = undefined;
    };
}

if (typeof document !== 'undefined') {
    registerProjectDeleted();
}
