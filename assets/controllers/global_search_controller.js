import { Controller } from '@hotwired/stimulus';
import { LazyFrame } from '../lib/lazy_frame.js';

export default class extends Controller {
    static targets = [
        'trigger',
        'dialog',
        'form',
        'query',
        'frame',
        'loading',
        'error',
        'close',
    ];

    get lazyFrame() {
        return new LazyFrame({
            frame: this.frameTarget,
            loading: this.loadingTarget,
            error: this.errorTarget,
        });
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    shortcut(event) {
        if (event.key === 'Escape' && this.dialogTarget.open) {
            event.preventDefault();
            this.closeTarget.click();
            return;
        }
        if (
            !(event.ctrlKey || event.metaKey) ||
            event.altKey ||
            event.key.toLowerCase() !== 'k'
        )
            return;
        const openDialog = document.querySelector('dialog[open]');
        if (openDialog && openDialog !== this.dialogTarget) return;
        event.preventDefault();
        if (this.dialogTarget.open) {
            this.queryTarget.focus();
        } else {
            this.triggerTarget.click();
        }
    }

    open() {
        this.queryTarget.focus();
        this.formTarget.requestSubmit();
    }

    search() {
        clearTimeout(this.timer);
        this.loading();
        this.timer = setTimeout(() => this.formTarget.requestSubmit(), 300);
    }

    submitted() {
        clearTimeout(this.timer);
    }

    loading() {
        this.lazyFrame.showLoading();
    }

    received(event) {
        const response = event.detail.fetchResponse;
        if (LazyFrame.refuses(event) || response.redirected) this.failed(event);
    }

    loaded() {
        const query = this.frameTarget.querySelector('[data-search-query]')
            ?.dataset.searchQuery;
        const currentQuery = this.queryTarget.value.replace(
            /^[\p{Z}\p{Cc}\p{Cf}]+|[\p{Z}\p{Cc}\p{Cf}]+$/gu,
            '',
        );
        if (query !== currentQuery) return;
        this.lazyFrame.showReady();
    }

    failed(event) {
        event.preventDefault();
        this.lazyFrame.showError();
    }
}
