import { Controller } from '@hotwired/stimulus';
import { unsubscribeThisBrowser } from '../lib/push_device.js';

const WAIT_LIMIT_MILLISECONDS = 1500;

/**
 * Drops this browser's push subscription before the sign-out form goes. The
 * wait has a limit, so a stuck browser call cannot hold the sign-out back.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    _resubmitting = false;

    async unsubscribe(event) {
        if (this._resubmitting) {
            return;
        }
        if (!navigator.serviceWorker || !('PushManager' in window)) {
            return;
        }

        event.preventDefault();
        let timer;
        const limit = new Promise((resolve) => {
            timer = setTimeout(resolve, WAIT_LIMIT_MILLISECONDS);
        });
        await Promise.race([unsubscribeThisBrowser(), limit]);
        clearTimeout(timer);

        this._resubmitting = true;
        this.element.requestSubmit();
    }
}
