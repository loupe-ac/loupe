import { on } from './live.js';
import { handleLostAccess, liveRequest } from './live_fetch.js';

let remove;

/**
 * Listens once for the deletion of the project of the page. The page names its
 * project in a [data-live-project] attribute. On a signal for that project it
 * reads the current page once, and the answer shows the lost-access band.
 *
 * @returns {() => void} removes the handler
 */
export function registerProjectDeleted() {
    remove ??= on('project.deleted', async (change) => {
        const marker = document.querySelector('[data-live-project]');
        if (
            change.own ||
            !marker ||
            marker.dataset.liveProject !== String(change.projectId)
        ) {
            return;
        }
        try {
            handleLostAccess(await liveRequest(window.location.href));
        } catch {
            // A failed read shows nothing. The next live fetch gets the same answer.
        }
    });

    return () => {
        remove?.();
        remove = undefined;
    };
}

if (typeof document !== 'undefined') {
    registerProjectDeleted();
}
