import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/**
 * Reloads a sidebar pill when the hub reports one of its events, and on each
 * open of the connection for any change made while it was not listening, the
 * first open included. A message that names a project is ignored when it
 * names another one, because one topic can carry every project of the user.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = {
        project: String,
        url: String,
        events: { type: Array, default: [] },
    };

    connect() {
        this.unsubscribe = on(
            this.eventsValue,
            (data) => {
                if (!data?.projectId || data.projectId === this.projectValue) {
                    this.reload();
                }
            },
            {
                onOpen: () => {
                    this.reload();
                    this.element.setAttribute('data-live-pill-connected', '');
                },
                onError: () =>
                    this.element.removeAttribute('data-live-pill-connected'),
            },
        );
    }

    disconnect() {
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute('data-live-pill-connected');
    }

    keepPill(event) {
        // A failed reload has no frame to show, and Turbo would write "Content missing" into the link.
        event.preventDefault();
    }

    reload() {
        // A new load cancels the one in flight, so the last change wins.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.src = this.urlValue;
        } else {
            this.frameTarget.reload();
        }
    }
}
