/**
 * The loading, ready and error states of a Turbo frame that loads on demand.
 * A controller keeps one per frame and shows the state its events call for.
 */
export class LazyFrame {
    constructor({ frame, loading, error }) {
        this.frame = frame;
        this.loading = loading;
        this.error = error;
    }

    showLoading() {
        this.loading.hidden = false;
        this.error.hidden = true;
        this.frame.hidden = true;
    }

    showReady() {
        this.loading.hidden = true;
        this.error.hidden = true;
        this.frame.hidden = false;
    }

    showError() {
        this.loading.hidden = true;
        this.error.hidden = false;
        this.frame.hidden = true;
    }

    /** True when a frame response carries no HTML page to show. */
    static refuses(event) {
        const response = event.detail.fetchResponse;
        return !response.succeeded || !response.isHTML;
    }
}
