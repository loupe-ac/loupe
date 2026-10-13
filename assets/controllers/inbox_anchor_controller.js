import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    // An old link names one item in the fragment, as #inbox-item-<number>. The
    // server picks the ask from the item number in the query, because it cannot
    // see a fragment. Rewrite the old address once, and drop the fragment.
    connect() {
        const url = new URL(window.location.href);
        const match = /^#inbox-item-(\d+)$/.exec(url.hash);
        if (!match) {
            return;
        }

        url.searchParams.set('item', match[1]);
        url.hash = '';
        window.location.replace(url.toString());
    }
}
