import { Controller } from '@hotwired/stimulus';

/**
 * The Loupe Explainer in the home page hero. The explainer is a static page in
 * a same-origin iframe, so the frame takes the height of that page.
 */
export default class extends Controller {
    connect() {
        this._resizeObserver = new ResizeObserver(() => this.fit());
        this.element.addEventListener('load', this._onLoad);
        if (this.element.contentDocument?.readyState === 'complete') {
            this._onLoad();
        }
    }

    disconnect() {
        this.element.removeEventListener('load', this._onLoad);
        this._resizeObserver.disconnect();
    }

    _onLoad = () => {
        const root = this.element.contentDocument?.documentElement;
        if (!root) {
            return;
        }
        this._resizeObserver.disconnect();
        this._resizeObserver.observe(root);
        this.fit();
    };

    fit() {
        const root = this.element.contentDocument?.documentElement;
        if (root) {
            this.element.style.height = `${root.scrollHeight}px`;
        }
    }
}
