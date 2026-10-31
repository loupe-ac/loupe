import { Controller } from '@hotwired/stimulus';

/**
 * Asks before the reader leaves a page with unsaved changes. The page says
 * whether it is dirty, because the controls are a GET form that reloads the
 * page on every change, so the browser never holds the change itself.
 *
 * Put it on the element that also holds the `modal` controller and its dialog.
 * A link click opens the dialog. A tab close or reload gets the native prompt.
 * A back or forward visit is not covered.
 */
export default class extends Controller {
    static values = { dirty: Boolean };

    connect() {
        this.pendingHref = null;
        this.submitting = false;
        document.addEventListener('click', this.#onClick);
        document.addEventListener('submit', this.#onSubmit);
        window.addEventListener('beforeunload', this.#onBeforeUnload);
    }

    disconnect() {
        document.removeEventListener('click', this.#onClick);
        document.removeEventListener('submit', this.#onSubmit);
        window.removeEventListener('beforeunload', this.#onBeforeUnload);
    }

    discard(event) {
        event?.preventDefault();
        const href = this.pendingHref;
        this.pendingHref = null;
        this.dirtyValue = false;
        this.#modal.close().then(() => {
            if (!href) return;
            if (window.Turbo) {
                window.Turbo.visit(href);
            } else {
                window.location.assign(href);
            }
        });
    }

    get #modal() {
        return this.application.getControllerForElementAndIdentifier(
            this.element,
            'modal',
        );
    }

    #onClick = (event) => {
        if (
            !this.dirtyValue ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }
        const link = event.target.closest?.('a[href]');
        if (
            !link ||
            link.target === '_blank' ||
            link.hasAttribute('download') ||
            this.#staysOnPage(link)
        ) {
            return;
        }
        event.preventDefault();
        this.pendingHref = link.href;
        this.#modal.open();
    };

    #staysOnPage(link) {
        const target = new URL(link.href, window.location.href);

        return (
            target.hash !== '' &&
            target.origin === window.location.origin &&
            target.pathname === window.location.pathname &&
            target.search === window.location.search
        );
    }

    #onSubmit = () => {
        this.submitting = true;
    };

    #onBeforeUnload = (event) => {
        if (!this.dirtyValue || this.submitting) return;
        event.preventDefault();
        event.returnValue = '';
    };
}
