import { Controller } from '@hotwired/stimulus';
import { LazyFrame } from '../lib/lazy_frame.js';

/**
 * Loads a Turbo frame when its drawer opens, and shows the loading, ready and
 * error states around it. Pair it with `Ds:Drawer` and the `modal` controller.
 */
export default class extends Controller {
    static targets = ['frame', 'loading', 'error'];
    static values = { url: String };

    connect() {
        this.state = new LazyFrame({
            frame: this.frameTarget,
            loading: this.loadingTarget,
            error: this.errorTarget,
        });
    }

    load() {
        this.state.showLoading();
        if (this.frameTarget.src) {
            this.frameTarget.reload();
        } else {
            this.frameTarget.src = this.urlValue;
        }
    }

    loaded() {
        this.state.showReady();
    }

    received(event) {
        if (LazyFrame.refuses(event)) this.failed(event);
    }

    failed(event) {
        event.preventDefault();
        this.state.showError();
    }
}
